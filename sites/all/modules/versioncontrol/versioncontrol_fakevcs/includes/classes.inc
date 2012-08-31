<?php
/**
 * @file
 *   An example of the classes a backend would need.
 *   Please notice this is just a subsetimplementation
 */
class VersioncontrolFakeBackend extends VersioncontrolBackend {

  public $classes = array(
    'repo'      => 'VersioncontrolFakeRepository',
    'operation' => 'VersioncontrolFakeOperation',
    'item'      => 'VersioncontrolFakeItem',
  );

  public function __construct() {
    // The user-visible name of the VCS.
    $this->name = 'FakeVCS';
    // A short description of the VCS, if possible not longer than one or
    // two sentences.
    $this->description = t('FakeVCS is a version control system that is specifically capable in doing everything that any other version control system might ever do.');
    // Our example VCS supports all possible capabilities, but they are
    // optional.
    $this->capabilities = array(
        // Able to retrieve a file or its revision number based on a global
        // revision identifier.
        VERSIONCONTROL_CAPABILITY_ATOMIC_COMMITS,

        // The version control system assigns revisions not only to files
        // but also to directories.
        VERSIONCONTROL_CAPABILITY_DIRECTORY_REVISIONS,
    );
  }

  /**
   * Overwrite
   */
  function isUsernameValid(&$username) {
    // Continuing the email-style username example from above.
    return valid_email_address($username);
  }

}

class VersioncontrolFakeRepository extends VersioncontrolRepository {

  public function formatRevisionIdentifier($revision, $format = 'full') {
    switch ($format) {
    case 'full':
    case 'short':
    default:
      // Suppose we're a distributed VCS backend and have an SHA-1 hash:
      // $revision == '30581e4ec3347d1294ec05a91eec1a8588e5993c'
      // Let's return only the first 12 characters of the revision identifier,
      // like Mercurial (including hgweb) does by default.
      return substr($revision, 0, 12);

      // SVN also wants to format revisions in a slightly custom way:
      return 'r'. $revision;
    }
  }

}

class VersioncontrolFakeOperation extends VersioncontrolOperation {

  public $parent_commit = '';

  /**
   * Implementation of abstract method.
   */
  public function getSelectedLabel($target_item) {
    // How CVS and many other version control systems will probably do it,
    // as they have exactly one label assigned to each operation.
    return $operation->labels[0];

    // If an operation - or more specifically, a commit - applies to multiple
    // branches and/or tags (as can happen in SVN, for example), then the correct
    // label for each item has to be determined.
  }

  // Asumming for fakevcs is better retrieving information if we have the
  // parent commit revision, we interact to have CRUD around that in out own
  // table.

  protected function backendInsert($options) {
    db_insert('versioncontrol_fakevcs_operations')
      ->fields(array(
        'vc_op_id' => $this->vc_op_id,
        'parent_commit' => $this->parent_commit,
      ))
      ->execute();
  }

  protected function backendUpdate($options) {
    db_update('versioncontrol_fakevcs_operations')
      ->fields(array(
        'vc_op_id' => $this->vc_op_id,
        'parent_commit' => $this->parent_commit,
      ))
      ->condition('vc_op_id', $this->vc_op_id)
      ->execute();
  }

  protected function backendDelete($options) {
    db_delete('versioncontrol_fakevcs_operations')
      ->condition('vc_op_id', $this->vc_op_id)
      ->execute();
  }
}

class VersioncontrolGitOperationController extends VersioncontrolOperationController {
  /**
   * Extend the base query with the fakevcs backend's additional data in
   * {versioncontrol_fakevcs_operations}.
   *
   * @return SelectQuery
   */
  protected function buildQueryBase($ids, $conditions) {
    $query = parent::buildQueryBase($ids, $conditions);
    $alias = $this->addTable($query, 'versioncontrol_fakevcs_operations', 'vcfo', 'base.vc_op_id = vcfo.vc_op_id');
    $query->fields($alias, drupal_schema_fields_sql('versioncontrol_fakevcs_operations'));
    return $query;
  }
}

class VersioncontrolFakeItem extends VersioncontrolItem {

  /**
   * Implementation of abstract method.
   */
  public function getSelectedLabelFromItem(&$other_item, $other_item_tags = array()) {
    // First up, optimizations - maybe we can do without the generic
    // "label transfer" code from further down and use assumptions
    // instead.

    // Let's assume for FakeVCS repositories that if an item wears a
    // label, then an item at another path but with the same (file-level)
    // revision can also wear that same label. That is the case with some
    // version control systems (e.g. Git, Mercurial, Bazaar) but might
    // not be the case with others (CVS for its lack of global revision
    // identifiers, SVN for its use of directory structure as tag/branch
    // identifiers).
    if ($item->revision == $other_item->revision) {
      return $other_item->getSelectedLabel();
    }

    // If the $other_item is a successor item on a branch, some version
    // control systems (like CVS) can guarantee that the $target_item,
    // the predecessor, is always on the same branch. So no need for
    // database queries, yay.
    if (in_array('successor_item', $other_item_tags)) {
      $label = $other_item->getSelectedLabel();

      if ($label instanceof VersioncontrolBranch) {
        return $label;
      }
    }

    // Otherwise we might not be able to derive the $target_item's label,
    // in which case we need to fall back to the database or a VCS
    // invocation.  For example, something like this.
    if ($target_item->fetchItemRevisionId()) {
      $commit_operations = $this->backend->loadEntity('item', array($target_item->item_revision_id));

      if (!empty($commit_operations)) { // yo, found the associated commit!
        // Code taken from the CVS backend, which only assigns a single
        // branch to any given commit operation, so it can just take the
        // first one.
        $commit_operation = reset($commit_operations); // first (only) element
        return $commit_operation->labels[0];
      }
    }

    // (You can even do more attempts if you want to, like trying to ask
    // the VCS itself. Or maybe not, depends on your motivation and the
    // VCS's capabilities.)

    // No label could be retrieved by looking at the other item, sorry.
    return NULL;
  }

}
