<?php
/*
 *  tables in the database: field_group, fields, options, and answers.

The field_group table contains information about the field groups, including the organization that created the group, the title of the group, the creation and update dates, and the organizations that performed the creation and update actions.

The fields table contains information about individual fields, including the title of the field, the field type, validation information, and placeholder text, as well as the group the field belongs to, and the organization that created the field.

The options table contains a list of options associated with each field, including the title and value of each option, and the field that the option is associated with.

The answers table stores the answers provided by the users for each field and form, including the value of the answer, the status of the answer, and the field and group the answer is associated with.

Each of the tables also includes indexing on certain columns, such as the organization_id and field_id, to improve the performance of database queries that search or filter based on these columns.
 */
class FieldGroup {
  private $id;
  private $title;
  private $fields;

  public function __construct($id, $title) {
    $this->id = $id;
    $this->title = $title;
    $this->fields = [];
  }

  public function getId() {
    return $this->id;
  }

  public function getTitle() {
    return $this->title;
  }

  public function addField(Field $field) {
    $this->fields[] = $field;
  }

  public function getFields() {
    return $this->fields;
  }
}

class Field {
  private $id;
  private $title;
  private $fieldType;
  private $options;

  public function __construct($id, $title, $fieldType) {
    $this->id = $id;
    $this->title = $title;
    $this->fieldType = $fieldType;
    $this->options = [];
  }

  public function getId() {
    return $this->id;
  }

  public function getTitle() {
    return $this->title;
  }

  public function getFieldType() {
    return $this->fieldType;
  }

  public function addOption(Option $option) {
    $this->options[] = $option;
  }

  public function getOptions() {
    return $this->options;
  }
}

class Option {
  private $id;
  private $title;

  public function __construct($id, $title) {
    $this->id = $id;
    $this->title = $title;
  }

  public function getId() {
    return $this->id;
  }

  public function getTitle() {
    return $this->title;
  }
}


/**
 *
Here is a dynamicForm class that retrieves the array tree of field groups, the fields of group, the fields of the
subgroup and the options of each fields by just giving the top group id:
 *
*/
class Hierarchy {

  public static function getFormStructure($topGroupId) {
    $groups = self::getGroups($topGroupId);
    foreach ($groups as &$group) {
      $group['fields'] = self::getFields($group['id']);
      foreach ($group['fields'] as &$field) {
        $field['options'] = self::getOptions($field['id']);
      }
    }
    return $groups;
  }

  private static function getGroups($parentId) {
    $groups = [];
    $query = "SELECT id, title FROM field_groups WHERE parent_id = :parentId";
    $data = [':parentId' => $parentId];
    Conn::dml($query, $data, function ($row) use (&$groups) {
      $groups[$row['id']] = [
          'id' => $row['id'],
          'title' => $row['title'],
      ];
    });
    return $groups;
  }

  private static function getFields($groupId) {
    $fields = [];
    $query = "SELECT id, title, field_type FROM fields WHERE group_id = :groupId";
    $data = [':groupId' => $groupId];
    Conn::dml($query, $data, function ($row) use (&$fields) {
      $fields[$row['id']] = [
          'id' => $row['id'],
          'title' => $row['title'],
          'fieldType' => $row['field_type'],
      ];
    });
    return $fields;
  }

  private static function getOptions($fieldId) {
    $options = [];
    $query = "SELECT id, title FROM options WHERE field_id = :fieldId";
    $data = [':fieldId' => $fieldId];
    Conn::dml($query, $data, function ($row) use (&$options) {
      $options[$row['id']] = [
          'id' => $row['id'],
          'title' => $row['title'],
      ];
    });
    return $options;
  }
}
