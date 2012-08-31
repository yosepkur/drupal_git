<?php
/**
 * @file
 * Repo class
 */

/**
 * Contain fundamental information about the repository.
 */
abstract class VersioncontrolRepository implements VersioncontrolEntityInterface, Serializable {
  protected $_id = 'repo_id';

  /**
   * db identifier
   *
   * @var    int
   */
  public $repo_id;

  /**
   * repository name inside drupal
   *
   * @var    string
   */
  public $name;

  /**
   * VCS string identifier
   *
   * @var    string
   */
  public $vcs;

  /**
   * where it is
   *
   * @var    string
   */
  public $root;

  /**
   * how ot authenticate
   *
   * @var    string
   */
  public $authorization_method = 'versioncontrol_admin';

  /**
   * The method that this repository will use to update the operations table.
   *
   * This should correspond to constants provided by the backend provider.
   *
   * @var    integer
   */
  public $update_method = 0;

  /**
   * Flag indicating that the repository is in an initial, empty state, and as
   * such should follow the syncInitial() logic branch on sync.
   *
   * @var int
   */
  public $init = 1;

  /**
   * The Unix timestamp when this repository was last updated.
   *
   * @var    integer
   */
  public $updated = 0;

  /**
   * Repository lock. Repositories are locked when running a parse job to ensure
   * duplicate data does not enter the database.
   *
   * Zero indicates an unlocked repository; any nonzero is a timestamp
   * the time of the last lock.
   *
   * @var integer
   */
  public $locked = 0;

  /**
   * An array of additional per-repository settings, mostly populated by
   * third-party modules. It is serialized on DB.
   */
  public $data = array();

  /**
   * The backend associated with this repository
   *
   * @var VersioncontrolBackend
   */
  protected $backend;

  protected $built = FALSE;

  /**
   * An array describing the plugins that will be used for this repository.
   *
   * The current plugin types(array keys) are:
   * - author_mapper
   * - committer_mapper
   * - webviewer_url_handler
   * - repomgr
   * - auth_handler
   *
   * @var array
   */
  public $plugins = array();

  /**
   * An array of plugin instances (instanciated plugin objects).
   *
   * @var array
   */
  protected $pluginInstances = array();

  protected $defaultCrudOptions = array(
    'update' => array('nested' => TRUE),
    'insert' => array('nested' => TRUE),
    'delete' => array('purge bypass' => TRUE),
  );

  /**
   * An array of default ctools plugin names keyed by plugin slot.
   *
   * @var array
   */
  protected $defaultPluginNames = array();

  public function __construct($backend = NULL) {
    if ($backend instanceof VersioncontrolBackend) {
      $this->backend = $backend;
    }
    else if (variable_get('versioncontrol_single_backend_mode', FALSE)) {
      $backends = versioncontrol_get_backends();
      $this->backend = reset($backends);
    }
  }

  public function getBackend() {
    return $this->backend;
  }

  /**
   * Convenience method to set the repository lock to a specific value.
   */
  public function updateLock($timestamp = NULL) {
    if (is_null($timestamp)) {
      $timestamp = time();
    }
    $this->locked = $timestamp;
  }

  /**
   * Pseudo-constructor method; call this method with an associative array or
   * stdClass object containing properties to be assigned to this object.
   *
   * @param array $args
   */
  public function build($args = array()) {
    // If this object has already been built, bail out.
    if ($this->built == TRUE) {
      return FALSE;
    }

    foreach ($args as $prop => $value) {
      $this->$prop = $value;
    }
    if (!empty($this->data) && is_string($this->data)) {
      $this->data = unserialize($this->data);
    }
    if (!empty($this->plugins) && is_string($this->plugins)) {
      $this->plugins = unserialize($this->plugins);
    }
    $this->built = TRUE;
  }

  /**
   * Perform a history synchronization, bringing the database fully in line with
   * the repository.
   *
   * @return bool
   *   Returns TRUE on a successful synchronization, FALSE on a failure.
   */
  public function sync() {
    // prep & insert the initial log object
    $log = new stdClass();
    $log->repo_id = $this->repo_id;
    $log->plugin = $this->getPluginName('reposync');
    $log->start = microtime(TRUE);
    $log->initial = $this->init;
    $ret = drupal_write_record('versioncontrol_sync_log', $log);

    try {
      if (TRUE == $this->init) {
        $this->getSynchronizer()->syncInitial();
      }
      else {
        $this->getSynchronizer()->syncFull();
      }

      $log->end = microtime(TRUE);
      $log->successful = 1;
    }
    catch (VersioncontrolSynchronizationException $e) {
      // error thrown, sync failed. unlock, log, and finish out.
      $this->unlock();

      $log->end = microtime(TRUE);
      $log->successful = 0;
      $log->message = 'Unrecoverable event sync failure exception message: ' . $e->getMessage() . PHP_EOL;
    }
    catch (Exception $e) {
      // some non-sync exception was thrown. weird situation.
      $this->unlock();

      $log->end = microtime(TRUE);
      $log->successful = 0;
      $log->message = "Unexpected exception of type '" . get_class($e) . "' with message: " . $e->getMessage() . PHP_EOL;
    }

    drupal_write_record('versioncontrol_sync_log', $log, 'slid');

    return (bool) $log->successful;
  }

  /**
   * Perform a history synchronization that processes an incoming event.
   *
   * @param VersioncontrolEvent $event
   *   A VersioncontrolEvent object containing repository state change
   *   information. It should already have been written to the database (it
   *   should have an elid).
   *
   * @return bool
   *   Returns TRUE on a successful synchronization, FALSE on a failure.
   */
  public function syncEvent(VersioncontrolEvent $event) {
    $sync = $this->getSynchronizer();

    // prep & insert the initial log object
    $log = new stdClass();
    $log->repo_id = $this->repo_id;
    $log->elid = $event->elid;
    $log->plugin = $this->getPluginName('reposync');
    $log->start = microtime(TRUE);
    $ret = drupal_write_record('versioncontrol_sync_log', $log);

    // Try to sync the event.
    try {
      $sync->syncEvent($event);
      // no problems. log it and finish up.

      $log->end = microtime(TRUE);
      $log->successful = 1;
      $log->fullsync_failover = 0;
    }
    catch (VersioncontrolNeedsFullSynchronizationException $e) {
      // we need to switch over and perform a full sync, then finish the evented
      // logic. first, ensure the repo is unlocked
      $this->unlock();
      // now, flip over to the full sync logic and hope that works.
      try {
        $sync->syncFull();
        // it did, great. finalize the event, log the situation, then finish.

        $log->end = microtime(TRUE);
        $log->successful = 1;
        $log->fullsync_failover = 1;
        $log->message = 'Event sync failure exception message: ' . $e->getMessage();
      }
      catch (VersioncontrolSynchronizationException $e2) {
        // nothin we can do. log the problem, then bail out.
        $this->unlock();

        $log->end = microtime(TRUE);
        $log->successful = 0;
        $log->fullsync_failover = 1;
        $log->message = 'Recoverable event sync failure exception message: ' . $e->getMessage() . PHP_EOL .
                        'Full sync failure exception message: ' . $e2->getMessage();
      }
    }
    catch (VersioncontrolSynchronizationException $e) {
      // there was a known exception, but one that cannot be solved with a
      // fullsync attempt. clean up and log the error, doing what we can with
      // the event.
      $this->unlock();

      $log->end = microtime(TRUE);
      $log->successful = 0;
      $log->fullsync_failover = 0;
      $log->message = 'Unrecoverable event sync failure exception message: ' . $e->getMessage() . PHP_EOL;
    }
    catch (Exception $e) {
      // some non-sync exception was thrown. weird situation.
      $this->unlock();

      $log->end = microtime(TRUE);
      $log->successful = 0;
      $log->fullsync_failover = 0;
      $log->message = "Unexpected exception of type '" . get_class($e) . "' with message: " . $e->getMessage() . PHP_EOL;
    }

    $this->finalizeEvent($event);

    drupal_write_record('versioncontrol_sync_log', $log, 'slid');

    return (bool) $log->successful;
  }

  /**
   * Return all sync logs associated with this repository.
   *
   * TODO this is a blunt tool. hone it.
   *
   * @return array
   *   An array of stdClass objects representing records from the
   *   {versioncontrol_sync_log} table.
   */
  public function getSyncLogs() {
    $logs = db_select('versioncontrol_sync_log', 'vsl')
      ->fields('vsl', drupal_schema_fields_sql('versioncontrol_sync_log'))
      // ->condition('vsl.repo_id', $this->repo_id)
      ->execute()->fetchAllAssoc('slid');

    foreach ($logs as &$log) {
      // normalize values in the log object from simple strings
      $log->start = (float) $log->start;
      $log->end = empty($log->end) ? 0 : (float) $log->end;
      $log->cron = (int) $log->cron;
      $log->fullsync_failover = (int) $log->fullsync_failover;
      $log->slid = (int) $log->slid;
      $log->repo_id = (int) $log->repo_id;
      $log->elid = (int) $log->elid;
    }
    return $logs;
  }

  /**
   * Return the sync log associated with the provided event, if any.
   *
   * @param VersioncontrolEvent $event
   *   The event for which to load the sync log.
   *
   * @return stdClass $log
   *   The sync log object for the requested event object.
   */
  public function getEventSyncLog(VersioncontrolEvent $event) {
    if (empty($event->elid)) {
      throw new Exception('Cannot fetch a sync log for an event that has not yet been saved or processed.', E_ERROR);
    }

    $log = db_select('versioncontrol_sync_log', 'vsl')
      ->fields('vsl', drupal_schema_fields_sql('versioncontrol_sync_log'))
      ->condition('vsl.elid', $event->elid)
      ->condition('vsl.repo_id', $this->repo_id)
      ->execute()->fetchObject();

    // normalize values in the log object from simple strings
    $log->start = (float) $log->start;
    $log->end = empty($log->end) ? 0 : (float) $log->end;
    $log->cron = (int) $log->cron;
    $log->fullsync_failover = (int) $log->fullsync_failover;
    $log->slid = (int) $log->slid;
    $log->repo_id = (int) $log->repo_id;
    $log->elid = (int) $log->elid;
    return $log;
  }

  /**
   * Delete all sync logs associated with this repository.
   */
  public function flushSyncLogs() {
    db_delete('versioncontrol_sync_log', 'vsl')
      ->condition('vsl.repo_id', $this->repo_id)
      ->execute();
  }

  /**
   * Perform a full history synchronization, but first purge all existing
   * repository data so that the sync job starts from scratch.
   *
   * This method triggers a special set of hooks so that projects which have
   * data dependencies on the serial ids of versioncontrol entities can properly
   * recover from the purge & rebuild.
   *
   * // FIXME this must be refactored so that hook invocations occur on the same
   *    side of queueing as the history sync.
   */
  public function reSyncFromScratch($bypass = TRUE) {
    // disable controller caching
    $this->getBackend()->disableControllerCaching();
    module_invoke_all('versioncontrol_repository_pre_resync', $this, $bypass);

    $this->purgeData($bypass);
    $this->sync();

    module_invoke_all('versioncontrol_repository_post_resync', $this, $bypass);
    // restore controller caching
    $this->getBackend()->restoreControllerCachingDefaults();
  }

  /**
   * Title callback for repository arrays.
   */
  public function titleCallback() {
    return check_plain($repository->name);
  }

  /**
   * Load known events from a repository from the database as an array of
   * VersioncontrolEvent-descended objects.
   *
   * @see VersioncontrolBackend::loadEntities()
   *
   * @return
   *   An associative array of event objects, keyed on their elid.
   */
  public function loadEvents($ids = array(), $conditions = array(), $options = array()) {
    $conditions['repo_id'] = $this->repo_id;
    $options['repository'] = $this;
    return $this->getBackend()->loadEntities('event', $ids, $conditions, $options);
  }

  /**
   * Load a single event from this repository out of the database.
   *
   * @see VersioncontrolRepository::loadEvents()
   *
   * @param int $id
   *   The elid identifying the desired event.
   *
   * @return VersioncontrolEvent
   */
  public function loadEvent($id) {
    $conditions = array('repo_id' => $this->repo_id);
    $options = array('repository' => $this);
    return $this->getBackend()->loadEntity('event', $id, $conditions, $options);
  }

  /**
   * Load known branches in a repository from the database as an array of
   * VersioncontrolBranch-descended objects.
   *
   * @see VersioncontrolBackend::loadEntities()
   *
   * @return
   *   An associative array of branch objects, keyed on their label_id.
   */
  public function loadBranches($ids = array(), $conditions = array(), $options = array()) {
    $conditions['repo_id'] = $this->repo_id;
    $options['repository'] = $this;
    return $this->getBackend()->loadEntities('branch', $ids, $conditions, $options);
  }

  /**
   * Load a single branch object from this repository out of the database.
   *
   * Either a label_id or a string must be provided. If both are provided,
   * both will be used as conditionals in the query.
   *
   * @param string $name
   *   The name identifying the branch to be loaded. Note that these are not
   *   guaranteed to be unique; if a non-unique name is given, the first record
   *   from the database will be returned.
   *
   * @param int $label_id
   *   The label_id identifying the branch to be loaded.
   *
   * @return VersioncontrolBranch
   */
  public function loadBranch($name = NULL, $label_id = array()) {
    $conditions = array('repo_id' => $this->repo_id);
    $options = array('repository' => $this);

    if (!empty($name)) {
      $conditions['name'] = $name;
    }
    elseif (empty($label_id)) {
      return FALSE;
    }

    return $this->getBackend()->loadEntity('branch', $label_id, $conditions, $options);
  }

  /**
   * Load known tags in a repository from the database as an array of
   * VersioncontrolTag-descended objects.
   *
   * @see VersioncontrolBackend::loadEntities()
   *
   * @return
   *   An associative array of label objects, keyed on their label_id.
   */
  public function loadTags($ids = array(), $conditions = array(), $options = array()) {
    $conditions['repo_id'] = $this->repo_id;
    $options['repository'] = $this;
    return $this->getBackend()->loadEntities('tag', $ids, $conditions, $options);
  }

  /**
   * Load a single tag object from this repository out of the database.
   *
   * Either a label_id or a string must be provided. If both are provided,
   * both will be used as conditionals in the query.
   *
   * @param string $name
   *   The name identifying the tag to be loaded. Note that these are not
   *   guaranteed to be unique; if a non-unique name is given, the first record
   *   from the database will be returned.
   *
   * @param int $label_id
   *   The label_id identifying the tag to be loaded.
   *
   * @return VersioncontrolTag
   */
  public function loadTag($name = NULL, $label_id = array()) {
    $conditions = array('repo_id' => $this->repo_id);
    $options = array('repository' => $this);

    if (!empty($name)) {
      $conditions['name'] = $name;
    }
    elseif (empty($label_id)) {
      return FALSE;
    }

    return $this->getBackend()->loadEntity('tag', $label_id, $conditions, $options);
  }

  /**
   * Load known commits in a repository from the database as an array of
   * VersioncontrolOperation-descended objects.
   *
   * @see VersioncontrolBackend::loadEntities()
   *
   * @return
   *   An associative array of commit objects, keyed on their vc_op_id.
   */
  public function loadCommits($ids = array(), $conditions = array(), $options = array()) {
    $conditions['type'] = VERSIONCONTROL_OPERATION_COMMIT;
    $conditions['repo_id'] = $this->repo_id;
    $options['repository'] = $this;
    return $this->getBackend()->loadEntities('operation', $ids, $conditions, $options);
  }

  /**
   * Load a single commit object from this repository out of the database.
   *
   * Either a vc_op_id or a revision string must be provided. If both are
   * provided, both will be used as conditionals in the query.
   *
   * @param string $revision
   *   The revision identifying the commit to be loaded. Note that these are not
   *   guaranteed to be unique; if a non-unique name is given, the first record
   *   from the database will be returned.
   *
   * @param int $vc_op_id
   *   The vc_op_id identifying the commit to be loaded.
   *
   * @return VersioncontrolOperation
   */
  public function loadCommit($revision = NULL, $vc_op_id = array()) {
    $conditions = array(
      'repo_id' => $this->repo_id,
      'type' => VERSIONCONTROL_OPERATION_COMMIT,
    );
    $options = array('repository' => $this);

    if (!empty($revision)) {
      $conditions['revision'] = $revision;
    }
    elseif (empty($vc_op_id)) {
      return FALSE;
    }

    return $this->getBackend()->loadEntity('operation', $vc_op_id, $conditions, $options);
  }

  public function save($options = array()) {
    return empty($this->repo_id) ? $this->insert($options) : $this->update($options);
  }

  /**
   * Update a repository in the database, and invoke the necessary hooks.
   *
   * The 'repo_id' and 'vcs' properties of the repository object must stay
   * the same as the ones given on repository creation,
   * whereas all other values may change.
   */
  public function update($options = array()) {
    if (empty($this->repo_id)) {
      // This is supposed to be an existing repository, but has no repo_id.
      throw new Exception('Attempted to update a Versioncontrol repository which has not yet been inserted in the database.', E_ERROR);
    }

    // Append default options.
    $options += $this->defaultCrudOptions['update'];

    drupal_write_record('versioncontrol_repositories', $this, 'repo_id');

    $this->backendUpdate($options);

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_entity_repository_update', $this);
    return $this;
  }

  protected function backendUpdate($options) {}

  /**
   * Insert a repository into the database, and call the necessary hooks.
   *
   * @return
   *   The finalized repository array, including the 'repo_id' element.
   */
  public function insert($options = array()) {
    if (!empty($this->repo_id)) {
      // This is supposed to be a new repository, but has a repo_id already.
      throw new Exception('Attempted to insert a Versioncontrol repository which is already present in the database.', E_ERROR);
    }

    // Append default options.
    $options += $this->defaultCrudOptions['insert'];

    // drupal_write_record() will fill the $repo_id property on $this.
    drupal_write_record('versioncontrol_repositories', $this);

    $this->backendInsert($options);

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_entity_repository_insert', $this);
    return $this;
  }

  protected function backendInsert($options) {}

  /**
   * Delete a repository from the database, and call the necessary hooks.
   * Together with the repository, all associated commits are deleted as
   * well.
   */
  public function delete($options = array()) {
    // Append default options.
    $options += $this->defaultCrudOptions['delete'];

    // Delete all contained data.
    $this->purgeData($options['purge bypass']);

    db_delete('versioncontrol_repositories')
      ->condition('repo_id', $this->repo_id)
      ->execute();

    $this->backendDelete($options);

    // Events aren't removed by purges
    db_delete('versioncontrol_event_log')
      ->condition('repo_id', $this->repo_id)
      ->execute();

    module_invoke_all('versioncontrol_entity_repository_delete', $this);
  }

  /**
   * Purge all synchronized history data from this repository.
   *
   * Optionally bypass the one-by-one API and do it with bulk commands to go
   * MUCH faster.
   *
   * @param bool $bypass
   *   Whether or not to bypass the API and perform all operations with a small
   *   number of large queries. Skips individual hook notifications, but fires
   *   its own hook and is FAR more efficient than running deletes
   *   entity-by-entity.
   */
  public function purgeData($bypass = TRUE) {
    $this->getBackend()->disableControllerCaching();
    if (empty($bypass)) {
      foreach ($this->loadBranches() as $branch) {
        $branch->delete();
      }
      foreach ($this->loadTags() as $tag) {
        $tag->delete();
      }
      foreach ($this->loadCommits() as $commit) {
        $commit->delete();
      }
      // Repo is purged, so set init appropriately.
      $this->init = 1;
      $this->update();
      $this->getBackend()->restoreControllerCachingDefaults();
    }
    else {
      $label_ids = db_select('versioncontrol_labels', 'vl')
        ->fields('vl', array('label_id'))
        ->condition('vl.repo_id', $this->repo_id)
        ->execute()->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($label_ids)) {
        db_delete('versioncontrol_operation_labels')
          ->condition('label_id', $label_ids)
          ->execute();
      }

      db_delete('versioncontrol_operations')
        ->condition('repo_id', $this->repo_id)
        ->execute();

      db_delete('versioncontrol_labels')
        ->condition('repo_id', $this->repo_id)
        ->execute();

      db_delete('versioncontrol_item_revisions')
        ->condition('repo_id', $this->repo_id)
        ->execute();

      // Repo is purged, so set init appropriately.
      $this->init = 1;
      $this->update();
      $this->getBackend()->restoreControllerCachingDefaults();

      module_invoke_all('versioncontrol_repository_bypassing_purge', $this);
    }
  }

  protected function backendDelete($options) {}

  /**
   * Produce a new backend-specific VersioncontrolEvent object using a blob
   * of incoming data.
   *
   * This method takes raw data produced by commit/receive hook scripts -
   * that is, hooks that are triggered when new code arrives in a repository
   * as a result of intentional user action (commit/push) - and translates it
   * into an event object.
   *
   * @param array $data
   *   The data that should be used to generate a new event. The structure and
   *   content of the array is entirely backend-specific, as it reflects the
   *   particular data produced by the backend's hook scripts.
   *
   * @return VersioncontrolEvent
   *   A typed VersioncontrolEvent subclass, corresponding to the backend type.
   *   The object will not have been persisted to permanent storage - that is
   *   left to the client's discretion.
   */
  abstract public function generateCodeArrivalEvent($data);

  /**
   * Take a raw event object and perform any necessary processing to get it into
   * its final form.
   *
   * Not all backends will need to do this.
   *
   * @param VersioncontrolEvent $event
   */
  abstract public function finalizeEvent(VersioncontrolEvent $event);

 /**
   * Format a revision identifier string, typically for human-readable output.
   *
   * @see VersioncontrolBackend::formatRevisionIdentifier().
   *
   * @param $revision
   *   The unformatted revision, as given in $operation->revision
   *   or $item->revision (or the respective table columns for those values).
   * @param $format
   *   Either 'full' for the original version, or 'short' for a more compact form.
   *   If the revision identifier doesn't need to be shortened, the results can
   *   be the same for both versions.
   */
  public function formatRevisionIdentifier($revision, $format = 'full') {
    return $this->getBackend()->formatRevisionIdentifier($revision, $format);
  }

  /**
   * Return the webviewer url handler plugin object that this repository is
   * configured to use for generating links to a web-based browser.
   *
   * @return VersioncontrolWebviewerUrlHandlerInterface
   */
  public function getUrlHandler() {
    if (!isset($this->pluginInstances['webviewer_url_handler'])) {
      $plugin = $this->getPlugin('webviewer_url_handler', 'webviewer_url_handlers');
      $class_name = ctools_plugin_get_class($plugin, 'handler');
      if (!class_exists($class_name)) {
        throw new Exception("Plugin '{$this->plugins['webviewer_url_handler']}' of type 'webviewer_url_handlers' does not contain a valid class name in handler slot 'handler'", E_WARNING);
        return FALSE;
      }
      if (isset($this->data['webviewer_base_url']) && !empty($this->data['webviewer_base_url'])) {
        $webviewer_base_url = $this->data['webviewer_base_url'];
      }
      else {
        $variable = 'versioncontrol_repository_' . $this->getBackend()->type . '_base_url_' . $plugin['name'];
        $webviewer_base_url = variable_get($variable, '');
      }
      $this->pluginInstances['webviewer_url_handler'] = new $class_name(
        $this, $webviewer_base_url, $plugin['url_templates']
      );
    }
    return $this->pluginInstances['webviewer_url_handler'];
  }

  /**
   * Get a ctools plugin based on plugin slot passed.
   */
  protected function getPlugin($plugin_slot, $plugin_type) {
    ctools_include('plugins');

    $plugin_name = $this->getPluginName($plugin_slot);

    $plugin = ctools_get_plugins('versioncontrol', $plugin_type, $plugin_name);
    if (!is_array($plugin) || empty($plugin)) {
      throw new Exception("Attempted to get a plugin of type '$plugin_type' named '$plugin_name', but no such plugin could be found.", E_WARNING);
    }

    // If $plugin_name is empty ctools_get_plugins() returns an array of plugins
    // instead of a single one. Default to the first one.
    if (empty($plugin_name)) {
      return reset($plugin);
    }
    else {
      return $plugin;
    }
  }


  /**
   * Retrieve the name of the ctools plugin to use, based on the plugin type and
   * slot passed.
   *
   * @param string $plugin_slot
   *   The plugin slot is a VCAPI-internal concept; it is a string used to
   *   identify the key in the backend array designating the preferred plugin
   *   plugin type, or in searchin gmagically named $conf vars.
   */
  protected function getPluginName($plugin_slot) {
    $plugin_name = FALSE;
    if (empty($this->plugins[$plugin_slot])) {
      // handle special case for two slots using the same plugin type
      if ($plugin_slot == 'committer_mapper' || $plugin_slot == 'author_mapper') {
        $variable = 'versioncontrol_repository_plugin_default_user_mapping_methods';
      }
      else {
        $variable = 'versioncontrol_repository_plugin_default_' . $plugin_slot;
      }
      $plugin_name = variable_get($variable, '');
    }
    else {
      $plugin_name = $this->plugins[$plugin_slot];
    }

    // couldn't get it from a variable, try from the local array of defaults.
    if (empty($plugin_name) && !empty($this->defaultPluginNames[$plugin_slot])) {
      $plugin_name = $this->defaultPluginNames[$plugin_slot];
    }

    // Lets try to get a default value from the backend if any.
    if (empty($plugin_name)) {
      $plugin_name = $this->getBackend()->getDefaultPluginName($plugin_slot);
    }

    if (empty($plugin_name)) {
      throw new Exception("A default plugin name could not be retrieved for plugin type '$plugin_slot'.", E_WARNING);
    }

    return $plugin_name;
  }

  /**
   * Get an instantiated plugin object based on a requested plugin slot, and the
   * plugin this repository object has assigned to that slot.
   *
   * Internal function - other methods should provide a nicer public-facing
   * interface. This method exists primarily to reduce code duplication involved
   * in ensuring error handling and sound loading of the plugin.
   */
  protected function getPluginClass($plugin_slot, $plugin_type, $class_type) {
    $plugin = $this->getPlugin($plugin_slot, $plugin_type);

    $class_name = ctools_plugin_get_class($plugin, $class_type);
    if (!class_exists($class_name)) {
      throw new Exception("Plugin slot '$plugin_slot' of type '$plugin_type' does not contain a valid class name in handler slot '$class_type', named '$class_name' class", E_WARNING);
      return FALSE;
    }

    $plugin_object = new $class_name();
    $this->getBackend()->verifyPluginInterface($this, $plugin_slot, $plugin_object);
    return $plugin_object;
  }

  /**
  * Return the auth handler plugin object that this repository is
  * configured to use for implementing ACLs on this repository.
  *
  * @return VersioncontrolAuthHandlerInterface
  */
  public function getAuthHandler() {
    if (!isset($this->pluginInstances['auth_handler'])) {
      $this->pluginInstances['auth_handler'] = $this->getPluginClass('auth_handler', 'vcs_auth', 'handler');
      $this->pluginInstances['auth_handler']->setRepository($this);
    }
    return $this->pluginInstances['auth_handler'];
  }

  public function getAuthorMapper() {
    if (!isset($this->pluginInstances['author_mapper'])) {
      $this->pluginInstances['author_mapper'] = $this->getPluginClass('author_mapper', 'user_mapping_methods', 'mapper');
    }
    return $this->pluginInstances['author_mapper'];
  }

  public function getCommitterMapper() {
    if (!isset($this->pluginInstances['committer_mapper'])) {
      $this->pluginInstances['committer_mapper'] = $this->getPluginClass('committer_mapper', 'user_mapping_methods', 'mapper');
    }

    return $this->pluginInstances['committer_mapper'];
  }

  /**
  * Return the repository manager plugin object that this repository is
  * configured to use for performing administrative actions against the on-disk
  * repository.
  *
  * @return VersioncontrolRepositoryManagerWorkerInterface
  */
  public function getRepositoryManager() {
    if (!isset($this->pluginInstances['repomgr'])) {
      $this->pluginInstances['repomgr'] = $this->getPluginClass('repomgr', 'repomgr', 'worker');
      $this->pluginInstances['repomgr']->setRepository($this);
    }

    return $this->pluginInstances['repomgr'];
  }

  /**
   * Return the history synchronizer plugin object that this repository is
   * configured to use for all sync behaviors.
   *
   * @return VersioncontrolRepositoryHistorySynchronizerInterface
   */
  public function getSynchronizer() {
    if (!isset($this->pluginInstances['reposync'])) {
      $this->pluginInstances['reposync'] = $this->getPluginClass('reposync', 'reposync', 'worker');
      $this->pluginInstances['reposync']->setRepository($this);
    }

    return $this->pluginInstances['reposync'];
  }

  /**
   * Convenience method to lock the repository in preparation for a
   * synchronization run.
   *
   * @throws VersioncontrolLockedRepositoryException
   *
   * @return bool
   *   Returns TRUE if the lock was obtained; otherwise, throws an exception.
   */
  public function lock() {
    if (!empty($this->locked)) {
      $msg = t('The repository @name at @root is locked, a sync is already in progress.', array('@name' => $repository->name, '@root' => $repository->root));
      throw new VersioncontrolLockedRepositoryException($msg, E_RECOVERABLE_ERROR);
    }
    $this->updateLock();
    $this->update();
    return TRUE;
  }

  /**
   * Convenience function to unlock the repository after a synchronization run.
   */
  public function unlock() {
    $this->updateLock(0);
    $this->update();
  }

  /**
   * Fulfills Serializable::serialize() interface.
   *
   * @return string
   */
  public function serialize() {
    $refl = new ReflectionObject($this);
    // Get all properties, except static ones.
    $props = $refl->getProperties(ReflectionProperty::IS_PRIVATE | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PUBLIC );

    $ser = array();
    foreach ($props as $prop) {
      if (in_array($prop->name, array('backend', 'pluginInstances'))) {
        // serializing the backend is too verbose; serializing pluginInstances
        // could get us into trouble with autoload before D7.
        continue;
      }
      $ser[$prop->name] = $this->{$prop->name};
    }
    return serialize($ser);
  }

  /**
   * Fulfills Serializable::unserialize() interface.
   *
   * @param string $string_rep
   */
  public function unserialize($string_rep) {
    foreach (unserialize($string_rep) as $prop => $val) {
      $this->$prop = $val;
    }
    // And add the backend, which was stripped out.
    $this->backend = versioncontrol_get_backends($this->vcs);
  }
}
