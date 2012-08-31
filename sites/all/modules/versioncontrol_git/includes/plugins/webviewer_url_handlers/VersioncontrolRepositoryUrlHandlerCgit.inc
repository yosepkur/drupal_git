<?php
/**
 * @file
 * Repository Url handler class for cgit.
 */

/**
 * cgit webviewer url handler class.
 */
class VersioncontrolRepositoryUrlHandlerCgit extends VersioncontrolRepositoryUrlHandler {

  /**
   * cgit do not need to have a hash base parameter to show an item log history.
   */
  public function getItemLogViewUrl($item) {
    $placeholders = array(
      '%repo_name' => $this->repository->name,
      '%path'     => substr($item->path, 1),
      '%revision' => $item->revision,
    );

    $label = $item->getSelectedLabel();
    if (isset($label->type) && $label->type == VERSIONCONTROL_LABEL_BRANCH) {
      $current_branch = $label->name;
    }
    if (isset($current_branch)) {
      $placeholders['%branch'] = '?h=' . $current_branch;
    }
    else {
      $placeholders['%branch'] = '';
    }

    $view_url = $item->isFile()
      ? $this->getTemplateUrl('file_view')
      : $this->getTemplateUrl('directory_view');

    return strtr($view_url, $placeholders);
  }

  /**
   * cgit do not need to have a hash base parameter to show an item content.
   */
  public function getItemViewUrl($item) {
    // Removed items has not blob hash
    if (is_null($item->blob_hash)) {
      return '';
    }
    $placeholders = array(
      '%repo_name' => $this->repository->name,
      '%path'     => substr($item->path, 1),
      '%revision' => $item->blob_hash,
    );

    $label = $item->getSelectedLabel();
    if (isset($label->type) && $label->type == VERSIONCONTROL_LABEL_BRANCH) {
      $current_branch = $label->name;
    }
    if (isset($current_branch)) {
      $placeholders['%branch'] = '&h=' . $current_branch;
    }
    else {
      $placeholders['%branch'] = '';
    }

    $view_url = $item->isFile()
      ? $this->getTemplateUrl('file_view')
      : $this->getTemplateUrl('directory_view');

    return strtr($view_url, $placeholders);
  }
}
