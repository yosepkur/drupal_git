<?php
/**
 * @file
 * Version Control API - An interface to version control systems whose
 * functionality is provided by pluggable back-end modules.
 *
 * This file contains module hooks for users of Version Control API, with API
 * documentation and a bit of example code.
 * Poins of interactions for VCS backends are not to be found in this file as
 * they are already documented in the fakevcs backend classes of the example
 * module versioncontrol_fakevcs.
 *
 * Copyright 2007, 2008, 2009 by Jakob Petsovits ("jpetso", http://drupal.org/user/56020)
 */

/**
 * Act just after a versioncontrol operation has been inserted.
 *
 * @param VersioncontrolOperation $operation
 *   The operation object.
 *
 * @ingroup Operations
 */
function hook_versioncontrol_entity_commit_insert(VersioncontrolOperation $operation) {
}

/**
 * Act just after a versioncontrol operation has been updated.
 *
 * @param VersioncontrolOperation $operation
 *   The operation object.
 *
 * @ingroup Operations
 */
function hook_versioncontrol_entity_commit_update(VersioncontrolOperation $operation) {
}

/**
 * Act just after a versioncontrol operation has been deleted.
 *
 * @param VersioncontrolOperation $operation
 *   The operation object.
 *
 * @ingroup Operations
 */
function hook_versioncontrol_entity_commit_delete(VersioncontrolOperation $operation) {
}

/**
 * Act just after a versioncontrol branch has been inserted.
 *
 * @param VersioncontrolBranch $operation
 *   The branch object.
 *
 * @ingroup Branches
 */
function hook_versioncontrol_entity_branch_insert(VersioncontrolBranch $branch) {
}

/**
 * Act just after a versioncontrol branch has been updated.
 *
 * @param VersioncontrolBranch $branch
 *   The branch object.
 *
 * @ingroup Branches
 */
function hook_versioncontrol_entity_branch_update(VersioncontrolBranch $branch) {
}

/**
 * Act just after a versioncontrol branch has been deleted.
 *
 * @param VersioncontrolBranch $branch
 *   The branch object.
 *
 * @ingroup Branches
 */
function hook_versioncontrol_entity_branch_delete(VersioncontrolBranch $branch) {
}

/**
 * Act just after a versioncontrol event has been inserted.
 *
 * @param VersioncontrolEvent $event
 *   The event object.
 *
 * @ingroup Events
 */
function hook_versioncontrol_entity_event_insert(VersioncontrolEvent $event) {
}

/**
 * Act just after a versioncontrol event has been updated.
 *
 * @param VersioncontrolEvent $event
 *   The event object.
 *
 * @ingroup Events
 */
function hook_versioncontrol_entity_event_update(VersioncontrolEvent $event) {
}

/**
 * Act just after a versioncontrol event has been deleted.
 *
 * @param VersioncontrolEvent $event
 *   The event object.
 *
 * @ingroup Events
 */
function hook_versioncontrol_entity_event_delete(VersioncontrolEvent $event) {
}

/**
 * Act just after a versioncontrol item has been inserted.
 *
 * @param VersioncontrolItem $item
 *   The item object.
 *
 * @ingroup Items
 */
function hook_versioncontrol_entity_item_insert(VersioncontrolItem $item) {
}

/**
 * Act just after a versioncontrol item has been updated.
 *
 * @param VersioncontrolItem $item
 *   The item object.
 *
 * @ingroup Items
 */
function hook_versioncontrol_entity_item_update(VersioncontrolItem $item) {
}

/**
 * Act just after a versioncontrol item has been deleted.
 *
 * @param VersioncontrolItem $item
 *   The item object.
 *
 * @ingroup Items
 */
function hook_versioncontrol_entity_item_delete(VersioncontrolItem $item) {
}

/**
 * Act just after a versioncontrol tag has been inserted.
 *
 * @param VersioncontrolTag $tag
 *   The tag object.
 *
 * @ingroup Tags
 */
function hook_versioncontrol_entity_tag_insert(VersioncontrolTag $tag) {
}

/**
 * Act just after a versioncontrol tag has been updated.
 *
 * @param VersioncontrolTag $tag
 *   The tag object.
 *
 * @ingroup Tags
 */
function hook_versioncontrol_entity_tag_update(VersioncontrolTag $tag) {
}

/**
 * Act just after a versioncontrol tag has been deleted.
 *
 * @param VersioncontrolTag $tag
 *   The tag object.
 *
 * @ingroup Tags
 */
function hook_versioncontrol_entity_tag_delete(VersioncontrolTag $tag) {
}

/**
 * Act just after a versioncontrol repository has been inserted.
 *
 * @param VersioncontrolRepository $repository
 *   The repository object.
 *
 * @ingroup Repositories
 * @ingroup Database change notification
 * @ingroup Target audience: All modules with repository specific settings
 */
function hook_versioncontrol_entity_repository_insert(VersioncontrolRepository $repository) {
  // TODO use here dbtng
  foreach ($ponies as $pony) {
    db_query("INSERT INTO {mymodule_ponies} (repo_id, pony) VALUES (%d, %s)", $repository->repo_id, $pony);
  }
}

/**
 * Act just after a versioncontrol repository has been updated.
 *
 * @param VersioncontrolRepository $repository
 *   The repository object.
 *
 * @ingroup Repositories
 * @ingroup Database change notification
 * @ingroup Target audience: All modules with repository specific settings
 */
function hook_versioncontrol_entity_repository_update(VersioncontrolRepository $repository) {
  $ponies = $repository->data['mymodule']['ponies'];
  db_query("DELETE FROM {mymodule_ponies} WHERE repo_id = %d", $repository->repo_id);
  // TODO use here dbtng
  foreach ($ponies as $pony) {
    db_query("INSERT INTO {mymodule_ponies} (repo_id, pony) VALUES (%d, %s)", $repository->repo_id, $pony);
  }
}

/**
 * Act just after a versioncontrol repository has been deleted.
 *
 * @param VersioncontrolRepository $repository
 *   The repository object.
 *
 * @ingroup Repositories
 * @ingroup Database change notification
 * @ingroup Target audience: All modules with repository specific settings
 */
function hook_versioncontrol_entity_repository_delete(VersioncontrolRepository $repository) {
  db_query("DELETE FROM {mymodule_ponies} WHERE repo_id = %d", $repository->repo_id);
}

/**
 * Register new authorization methods that can be selected for a repository.
 * A module may restrict access and alter forms depending on the selected
 * authorization method which is a property of every repository array
 * ($repository['authorization_method']).
 *
 * A list of all authorization methods can be retrieved
 * by calling versioncontrol_get_authorization_methods().
 *
 * @return
 *   A structured array containing information about authorization methods
 *   provided by this module, wrapped in a structured array. Array keys are
 *   the unique string identifiers of each authorization method, and
 *   array values are the user-visible method descriptions (wrapped in t()).
 *
 * @ingroup Accounts
 * @ingroup Authorization
 * @ingroup Target audience: Authorization control modules
 */
function hook_versioncontrol_authorization_methods() {
  return array(
    'mymodule_code_ninja' => t('Code ninja skills required'),
  );
}
