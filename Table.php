<?
class Table
{
	private $tablename;
	private $prikey;
	private $fields = [];

	public function __get($property) {
		if (isset($this->fields[$property])) {
		  return $this->fields[$property];
		}
	}
	
    public function setTableName($table_name) {
        $this->tablename = $table_name;
    }
	
	public function setPriKey($field_name) {
        $this->prikey = $field_name;
    }
	
	public function addFields(array $fields = []) {
        $this->fields = $fields;
    }
	
	public function getFieldsName($with_pri_key = true) {
		$result = [];
        foreach ($this->fields as $field_name => $field_item) {
			if (!$with_pri_key && $field_name == $this->prikey) continue;
			$result[] = $field_name;
		}
		
		return $result;
    }
	
	public function getEmptyRecord() {
		$empty_by_type = ['literal' => '', 'digital' => 0];
		$result = [];
        foreach ($this->fields as $field_name => $field_item) {
			$result[$field_name] = $empty_by_type[$field_item['type']];
		}
		
		return $result;
    }
	
	private function makeCondition($key_value) {
		$where = [];
        if (!empty($key_value)) {
			if (!is_array($key_value)) {
				$key_value = !empty($this->prikey) ? [$this->prikey => $key_value] : [];
			}
			foreach ($key_value as $field_key => $field_value) {
				if (!empty($this->fields[$field_key])) {
					$field = $this->fields[$field_key];
					$field_name = "`".$field_key."`";
					if ($field['type'] == 'literal') $field_value = "'{$field_value}'";
					$where[] = "$field_name = $field_value";
				}
			}
        }
		
		return trim(implode(' AND ', $where));
	}

	private function clearSlashes($data = []) {
		foreach ($data as $field_key => $field_value) {
			if (isset($this->fields[$field_key]['type']) && $this->fields[$field_key]['type'] == 'literal') $data[$field_key] = stripcslashes($field_value);
		}
		return $data;
	}
	
	public function addRecord(array $data = []) {
		$result = [];
		$fieldset = [];
		$dataset = [];
		$rowset = [];
		$field_as_array = [];
		$enable_fields = array_intersect_key($data, $this->fields);
		$empty_by_type = ['literal' => "''", 'digital' => 0];
		$max_num = 0;
		foreach ($enable_fields as $key => $value) {
			if ($key == $this->prikey) continue;
			if (!is_array($value)) {
				$value = mysql2_real_escape_string($value);
				$fieldset[] = $key;
				$rowset[] = $this->fields[$key]['type'] == 'literal' ? "'{$value}'" : $value;
			} else {
				$field_as_array[$key] = $value;
				$max_num = $max_num < count($value) ? count($value) : $max_num;
			}
		}
		if (!empty($field_as_array)) {
			foreach ($field_as_array as $key => $values_arr) {
				array_push($fieldset, $key);
				for ($i = 0; $i <= ($max_num - 1); $i++) {
					if (empty($dataset[$i])) $dataset[$i] = $rowset;
					$current_val = !empty($values_arr[$i]) ? mysql2_real_escape_string($values_arr[$i]) : '';
					$current_val = !empty($current_val) ? ($this->fields[$key]['type'] == 'literal' ? "'{$current_val}'" : $current_val) : $empty_by_type[$this->fields[$key]['type']];
					array_push($dataset[$i], $current_val);
				}
			}
		} else {
			$dataset[] = $rowset;
		}
		
		if (!empty($fieldset) && !empty($dataset)) {
			$values_str = '';
			foreach ($dataset as $row_i) {
				$values_str .= "(".implode(',',$row_i)."),";
			}
			$values_str = trim($values_str, ',');
			$query_str = "INSERT INTO `".$this->tablename."` (`".implode('`,`',$fieldset)."`) VALUES $values_str";
			if (mysql2_query($query_str)) {
				$result[] = mysql2_affected_rows();
				$result[] = mysql2_insert_id();
				//$result[] = $query_str;
			}
		}
		
		return $result;
	}
	
	public function getRecord($key_value, array $params = []) {
		$dataset = [];
		$fields = '*';
		$where = $this->makeCondition($key_value);
		if (!empty($where)) {
			if (!empty($params['fields']) && is_array($params['fields'])) {
				$fields = "`".implode('`,`', array_keys(array_intersect_key($this->fields, $params['fields'])))."`";
			}
			//var_dump("SELECT $fields FROM " . $this->tablename . " WHERE $where");
			$result = mysql2_query("SELECT $fields FROM " . $this->tablename . " WHERE $where");
			if ($result) {
				$dataset = mysql2_fetch_assoc($result);
				$dataset = $this->clearSlashes($dataset);
			}
		}
		
		return $dataset;
	}
	
	public function getRecordsList(array $params = []) {
		$dataset = [];
		$fields = '*';
		if (!empty($params['fields']) && is_array($params['fields'])) $fields = "`".implode('`,`', array_keys(array_intersect_key($this->fields, $params['fields'])))."`";
		$where_arr = [];
		$where = '';
		if (!empty($params['where']) && is_array($params['where'])) {
			foreach (array_intersect_key($params['where'], $this->fields) as $key => $val) {
				if (is_array($val)) {
					$condition_group = [];
					foreach ($val as $val_i) {
						$condition_group[] = "`$key` = ".($this->fields[$key]['type'] == 'literal' ? "'{$val_i}'" : $val_i);
					}
					$current_condition = "(".implode(' OR ', $condition_group).")";
				} else {
					$current_condition = "`$key` = ".($this->fields[$key]['type'] == 'literal' ? "'{$val}'" : $val);
				}
				$where_arr[] = $current_condition;
			}
			$where = implode(' AND ', $where_arr);
		}
		if (empty($where)) $where = 1;
		$order_arr = [];
		$order = '';
		if (!empty($params['order']) && is_array($params['order'])) {
			foreach (array_intersect_key($params['order'], $this->fields) as $key => $val) {
				if (!is_array($val)) {
					$order_arr[] = "`".$key."`".(strtolower($val) == 'desc' ? ' DESC' : '');
				} else {
					if (!empty($val['condition'])) $order_arr[] = $val['condition'].(isset($val['by']) && strtolower($val['by']) == 'desc' ? ' DESC' : '');
				}
			}
			$order = 'ORDER BY '.implode(',', $order_arr);
		}
		$limit = '';
		if (!empty($params['limit']) && is_array($params['limit'])) {
			$limit = 'LIMIT '.implode(',', $params['limit']);
		}
		
		$result = mysql2_query("SELECT $fields FROM `".$this->tablename."` WHERE $where $order $limit");
		while ($row = mysql2_fetch_assoc($result)) {
			$row = $this->clearSlashes($row);
			if (!empty($params['result_dataset_key']) && isset($row[$params['result_dataset_key']])) {
				$dataset[$row[$params['result_dataset_key']]] = $row;
			} elseif (isset($row[$this->prikey])) {
				$dataset[$row[$this->prikey]] = $row;
			} else {
				$dataset[] = $row;
			}
		}
		
		//var_dump("SELECT $fields FROM " . $this->tablename . " WHERE $where $order $limit");
		return $dataset;
	}
	
	public function delRecord($key_value) {
		$result = [];
		$where = $this->makeCondition($key_value);
		if (!empty($where)) {
            $query_str = "DELETE FROM `".$this->tablename."` WHERE $where";
			if (mysql2_query($query_str)) {
				$result[] = mysql2_affected_rows();
				//$result[] = $query_str;
			}
        }
		
		return $result;
    }
	
	public function updRecord(array $params = []) {
		$result = [];
		$records_affect = 0;
		$affected_fields_title = [];
		$key_value = !empty($params['key_value']) ? $params['key_value'] : '';
		$set_conditions = [];
		if (!empty($params['set_values']) && is_array($params['set_values'])) {
			foreach ($params['set_values'] as $field_key => $field_value) {
				if (!empty($this->fields[$field_key])) {
					$field_value = mysql2_real_escape_string($field_value);
					$field = $this->fields[$field_key];
					$affected_fields_title[] = $field['title'];
					$field_name = "`".$field_key."`";
					if ($field['type'] == 'literal') $field_value = "'{$field_value}'";
					$set_conditions[] = "$field_name = $field_value";
				}
			}
		}
		if (!empty($key_value) && !empty($set_conditions)) {
			$where = $this->makeCondition($key_value);
			$set = implode(',', $set_conditions);
			$query_str = "UPDATE `".$this->tablename."` SET $set WHERE $where";
			if (mysql2_query($query_str)) {
				$records_affect = mysql2_affected_rows();
			}
		}
		if ($records_affect > 0) {
			$result[] = $records_affect;
			$result[] = implode(',', $affected_fields_title);
		}
		
		return $result;
	}	
}

?>