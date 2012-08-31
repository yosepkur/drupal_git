<?php
/**
 * @file
 * Contains the attribution argument handler.
 */

/**
 * Specialized argument handler for repo_id argument.
 */
class versioncontrol_handler_argument_operation_attribution_uid extends views_handler_argument_user_uid {
  function query() {
    $this->ensure_my_table();
    $this->query->add_field($this->table_alias, 'author_uid');
    $this->query->add_field($this->table_alias, 'committer_uid');

    if (!empty($this->options['break_phrase'])) {
      views_break_phrase($this->argument, $this);
    }
    else {
      $this->value = array($this->argument);
    }

    if (count($this->value) > 1) {
      $operator = empty($this->options['not']) ? 'IN' : 'NOT IN';
      $placeholders = implode(', ', array_fill(0, sizeof($this->value), '%d'));
      $this->query->add_where(0, "$this->table_alias.$this->real_field $operator ($placeholders)", $this->value);
    }
    else {
      $operator = empty($this->options['not']) ? '=' : '!=';
      $this->query->add_where(0, "$this->table_alias.author_uid $operator %d OR $this->table_alias.committer_uid $operator %d", $this->argument, $this->argument);
    }
  }
}
