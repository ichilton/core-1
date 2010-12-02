<?php
/**
 * Fuel
 *
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package		Fuel
 * @version		1.0
 * @author		Fuel Development Team
 * @license		MIT License
 * @copyright	2010 Dan Horrigan
 * @link		http://fuelphp.com
 */

namespace ActiveRecord;

use Fuel\Application as App;

class Model {

	protected $columns = array();

	protected $attributes = array();

	protected $associations = array();

	protected $is_modified = false;

	protected $frozen = false;

	protected $primary_key = 'id';

	protected $table_name;

	public $new_record = true;

	private $assoc_types = array('belongs_to', 'has_many', 'has_one');

	public function __construct($params=null, $new_record=true, $is_modified=false)
	{
		// Setup all the associations
		foreach ($this->assoc_types as $type)
		{
			if (isset($this->{$type}))
			{
				$class_name = Inflector::classify($type);

				foreach ($this->{$type} as $assoc)
				{
					/* handle association sent in as array with options */
					if (is_array($assoc))
					{
						$key = key($assoc);
						$this->{$key} = new $class_name($this, $key, current($assoc));
					}
					else
					{
						$this->{$assoc} = new $class_name($this, $assoc);
					}
				}
			}
		}

		if (is_array($params))
		{
			foreach ($params as $key => $value)
			{
				$this->{$key} = $value;
			}
			$this->is_modified = $is_modified;
			$this->new_record = $new_record;
		}
	}

	public function __get($name)
	{
		if (array_key_exists($name, $this->attributes))
		{
			return $this->attributes[$name];
		}
		elseif (array_key_exists($name, $this->associations))
		{
			return $this->associations[$name]->get($this);
		}
		elseif (in_array($name, $this->columns))
		{
			return null;
		}
		elseif (preg_match('/^(.+?)_ids$/', $name, $matches))
		{
			/* allow for $p->comment_ids type gets on HasMany associations */
			$assoc_name = App\Inflector::pluralize($matches[1]);
			if ($this->associations[$assoc_name] instanceof HasMany)
				return $this->associations[$assoc_name]->get_ids($this);
		}
		throw new Exception("attribute called '$name' doesn't exist",
				Exception::AttributeNotFound);
	}

	public function __set($name, $value)
	{
		if ($this->frozen)
			throw new Exception("Can not update $name as object is frozen.", Exception::ObjectFrozen);

		/* allow for $p->comment_ids type sets on HasMany associations */
		if (preg_match('/^(.+?)_ids$/', $name, $matches))
		{
			$assoc_name = App\Inflector::pluralize($matches[1]);
		}

		if (in_array($name, $this->columns))
		{
			$this->attributes[$name] = $value;
			$this->is_modified = true;
		}
		elseif ($value instanceof Association)
		{
			/* call from constructor to setup association */
			$this->associations[$name] = $value;
		}
		elseif (array_key_exists($name, $this->associations))
		{
			/* call like $comment->post = $mypost */
			$this->associations[$name]->set($value, $this);
		}
		elseif (isset($assoc_name)
				&& array_key_exists($assoc_name, $this->associations)
				&& $this->associations[$assoc_name] instanceof HasMany)
		{
			/* allow for $p->comment_ids type sets on HasMany associations */
			$this->associations[$assoc_name]->set_ids($value, $this);
		}
		else
			throw new Exception("attribute called '$name' doesn't exist",
					Exception::AttributeNotFound);
	}

	/* on any ActiveRecord object we can make method calls to a specific assoc.
	  Example:
	  $p = Post::find(1);
	  $p->comments_push($comment);
	  This calls push([$comment], $p) on the comments association
	 */

	public function __call($name, $args)
	{
		// find longest available association that matches beginning of method
		$longest_assoc = '';
		foreach (array_keys($this->associations) as $assoc)
		{
			if (strpos($name, $assoc) === 0 &&
					strlen($assoc) > strlen($longest_assoc))
			{
				$longest_assoc = $assoc;
			}
		}

		if ($longest_assoc !== '')
		{
			list($null, $func) = explode($longest_assoc . '_', $name, 2);
			return $this->associations[$longest_assoc]->$func($args, $this);
		}
		else
		{
			throw new Exception("method or association not found for ($name)", Exception::MethodOrAssocationNotFound);
		}
	}


	public function get_columns()
	{
		return $this->columns;
	}

	public function get_primary_key()
	{
		return $this->primary_key;
	}

	public function is_frozen()
	{
		return $this->frozen;
	}

	public function is_new_record()
	{
		return $this->new_record;
	}

	public function is_modified()
	{
		return $this->is_modified;
	}

	public function set_modified($val)
	{
		$this->is_modified = $val;
	}

	public function update_attributes($attributes)
	{
		foreach ($attributes as $key => $value)
		{
			$this->$key = $value;
		}

		return $this->save();
	}

	public function save()
	{
		if (method_exists($this, 'before_save'))
		{
			$this->before_save();
		}

		foreach ($this->associations as $name => $assoc)
		{
			if ($assoc instanceOf BelongsTo && $assoc->needs_saving())
			{
				$this->$name->save();
				/* after our save, $this->$name might have new id;
				  we want to update the foreign key of $this to match;
				  we update this foreign key already as a side-effect
				  when calling set() on an association
				 */
				$this->$name = $this->$name;
			}
		}
		if ($this->new_record)
		{
			if (method_exists($this, 'before_create'))
			{
				$this->before_create();
			}

			foreach ($this->columns as $column)
			{
				if ($column == $this->primary_key)
				{
					continue;
				}

				if (is_null($this->$column))
				{
					$values[] = 'NULL';
				}
				else
				{
					$values[] = $this->$column;
				}
			}
			$res = DB::insert($this->table_name, $this->columns)->values($values)->execute();

			$this->{$this->primary_key} = $res[0];
			$this->new_record = false;
			$this->is_modified = false;

			if (method_exists($this, 'after_create'))
			{
				$this->after_create();
			}
		}
		elseif ($this->is_modified)
		{
			if (method_exists($this, 'before_update'))
			{
				$this->before_update();
			}

			$values = array();

			foreach ($this->columns as $column)
			{
				if ($column == $this->primary_key)
				{
					continue;
				}
				$values[$column] = is_null($this->$column) ? 'NULL' : $this->$column;
			}
			$res = DB::update($this->table_name)
						->set($values)
						->where($this->primary_key, '=', $this->{$this->primary_key})
						->limit(1)
						->execute();

			$this->new_record = false;
			$this->is_modified = false;
			
			if (method_exists($this, 'after_update'))
			{
				$this->after_update();
			}
		}
		foreach ($this->associations as $name => $assoc)
		{
			if ($assoc instanceOf HasOne && $assoc->needs_saving())
			{
				/* again sorta weird, this will update foreign key as needed */
				$this->$name = $this->$name;
				/* save the object referenced by this association */
				$this->$name->save();
			}
			elseif ($assoc instanceOf HasMany && $assoc->needs_saving())
			{
				$assoc->save_as_needed($this);
			}
		}
		if (method_exists($this, 'after_save'))
		{
			$this->after_save();
		}
	}

	public function destroy()
	{
		if (method_exists($this, 'before_destroy'))
		{
			$this->before_destroy();
		}
		foreach ($this->associations as $name => $assoc)
		{
			$assoc->destroy($this);
		}

		DB::delete($this->table_name)
				->where($this->primary_key, '=', $this->{$this->primary_key})
				->limit(1)
				->execute();

		$this->frozen = true;
		
		if (method_exists($this, 'after_destroy'))
		{
			$this->after_destroy();
		}
		return true;
	}

	/* transform_row -- transforms a row into its various objects
	  accepts: row from SQL query (array), lookup array of column names
	  return: object keyed by table names and real columns names
	 */

	static function transform_row($row, $col_lookup)
	{
		$object = array();
		foreach ($row as $col_name => $col_value)
		{
			/* set $object["table_name"]["column_name"] = $col_value */
			$object[$col_lookup[$col_name]["table"]][$col_lookup[$col_name]["column"]] = $col_value;
		}
		return $object;
	}

	public static function find($id, $options = null)
	{
		$query = self::generate_find_query($class, $id, $options);
		$rows = self::query($query['query']);
		#var_dump($query['query']);
		#$objects = self::transform_rows($rows, $query['column_lookup']);
		$base_objects = array();
		foreach ($rows as $row)
		{
			/* if we've done a join we have some fancy footwork to do
			  we're going to process one row at a time.
			  each row has a "base" object and objects that've been joined.
			  the base object is whatever class we've been passed as $class.
			  we only want to create one instance of each unique base object.
			  as we see more rows we may be re-using an exising base object to
			  append more join objects to its association.
			 */
			if (count($query['column_lookup']) > 0)
			{
				$objects = self::transform_row($row, $query['column_lookup']);
				$ob_key = md5(serialize($objects[App\Inflector::tableize($class)]));
				/* set cur_object to base object for this row; reusing if possible */
				if (array_key_exists($ob_key, $base_objects))
				{
					$cur_object = $base_objects[$ob_key];
				}
				else
				{
					$cur_object = new $class($objects[Inflector::tableize($class)], false);
					$base_objects[$ob_key] = $cur_object;
				}

				/* now add association data as needed */
				foreach ($objects as $table_name => $attributes)
				{
					if ($table_name == Inflector::tableize($class))
						continue;
					foreach ($cur_object->associations as $assoc_name => $assoc)
					{
						if ($table_name == Inflector::pluralize($assoc_name))
							$assoc->populate_from_find($attributes);
					}
				}
			}
			else
			{
				$item = new $class($row, false);
				array_push($base_objects, $item);
			}
		}
		if (count($base_objects) == 0 && (is_array($id) || is_numeric($id)))
			throw new Exception("Couldn't find anything.", Exception::RecordNotFound);
		return (is_array($id) || $id == 'all') ?
				array_values($base_objects) :
				array_shift($base_objects);
	}

	function generate_find_query($class_name, $id, $options=null)
	{
		//$dbh =& $this->get_dbh();
		$item = new $class_name;
		$options = self::decode_if_json($options);

		/* first sanitize what we can */
		if (is_array($id))
		{
			foreach ($id as $k => $v)
			{
				$id[$k] = self::quote($v);
			}
		}
		elseif ($id != 'all' && $id != 'first')
		{
			$id = self::quote($id);
		}
		/* regex for limit, order, group */
		$regex = '/^[A-Za-z0-9\-_ ,\(\)]+$/';
		if (!isset($options['limit']) || !preg_match($regex, $options['limit']))
			$options['limit'] = '';
		if (!isset($options['order']) || !preg_match($regex, $options['order']))
			$options['order'] = '';
		if (!isset($options['group']) || !preg_match($regex, $options['group']))
			$options['group'] = '';
		if (!isset($options['offset']) || !is_numeric($options['offset']))
			$options['offset'] = '';

		$select = '*';
		if (is_array($id))
			$where = "{$item->primary_key} IN (" . implode(",", $id) . ")";
		elseif ($id == 'first')
			$limit = '1';
		elseif ($id != 'all')
			$where = "{$item->table_name}.{$item->primary_key} = $id";

		if (isset($options['conditions']))
		{
			$cond = self::convert_conditions_to_where($options['conditions']);
			$where = (isset($where) && $where) ? $where . " AND " . $cond : $cond;
		}

		if ($options['offset'])
			$offset = $options['offset'];
		if ($options['limit'] && !isset($limit))
			$limit = $options['limit'];
		if (isset($options['select']))
			$select = $options['select'];
		$joins = array();
		$tables_to_columns = array();
		$column_lookup = array();
		if (isset($options['include']))
		{
			array_push($tables_to_columns,
					array(Inflector::tableize(get_class($item)) => $item->get_columns()));
			$includes = preg_split('/[\s,]+/', $options['include']);
			// get join part of query from association and column names
			foreach ($includes as $include)
			{
				if (isset($item->associations[$include]))
				{
					list($cols, $join) = $item->associations[$include]->join();
					array_push($joins, $join);
					array_push($tables_to_columns, $cols);
				}
			}
			// set the select variable so all column names are unique
			$selects = array();
			foreach ($tables_to_columns as $table_key => $columns)
			{
				foreach ($columns as $table => $cols)
					foreach ($cols as $key => $col)
					{
						array_push($selects, "$table.`$col` AS t{$table_key}_r$key");
						$column_lookup["t{$table_key}_r{$key}"]["table"] = $table;
						$column_lookup["t{$table_key}_r{$key}"]["column"] = $col;
					}
			}
			$select = implode(", ", $selects);
		}
		// joins (?), include

		$query = "SELECT $select FROM {$item->table_name}";
		$query .= ( count($joins) > 0) ? " " . implode(" ", $joins) : "";
		$query .= ( isset($where)) ? " WHERE $where" : "";
		$query .= ( $options['group']) ? " GROUP BY {$options['group']}" : "";
		$query .= ( $options['order']) ? " ORDER BY {$options['order']}" : "";
		$query .= ( isset($limit) && $limit) ? " LIMIT $limit" : "";
		$query .= ( isset($offset) && $offset) ? " OFFSET $offset" : "";
		return array('query' => $query, 'column_lookup' => $column_lookup);
	}

	public static function convert_conditions_to_where($conditions)
	{
		if (is_string($conditions))
		{
			return " ( " . $conditions . " ) ";
		}
		/* handle both normal array with place holders
		  and associative array */
		if (is_array($conditions))
		{
			// simple array
			if (reset(array_keys($conditions)) === 0 &&
					end(array_keys($conditions)) === count($conditions) - 1 &&
					!is_array(end($conditions)))
			{
				$condition = " ( " . array_shift($conditions) . " ) ";
				foreach ($conditions as $value)
				{
					$value = self::quote($value);
					$condition = preg_replace('|\?|', $value, $condition, 1);
				}
				return $condition;
			}
			/* array starts with a key of 0
			  next element can be an associative array of bind variables
			  or array can continue with bind variables with keys specified */
			elseif (reset(array_keys($conditions)) === 0)
			{
				$condition = " ( " . array_shift($conditions) . " ) ";
				if (is_array(reset($conditions)))
				{
					$conditions = reset($conditions);
				}

				foreach ($conditions as $key => $value)
				{
					$value = self::quote($value);
					$condition = preg_replace("|:$key|", $value, $condition, 1);
				}
				return $condition;
			}
			// associative array
			else
			{
				$condition = " ( ";
				$w = array();
				foreach ($conditions as $key => $value)
				{
					if (is_array($value))
					{
						$w[] = '`' . $key . '` IN ( ' . join(", ", $value) . ' )';
					}
					else
					{
						$w[] = '`' . $key . '` = ' . self::quote($value);
					}
				}
				return $condition . join(" AND ", $w) . " ) ";
			}
		}
	}

}


/* End of file model.php */