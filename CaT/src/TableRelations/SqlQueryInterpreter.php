<?php
namespace CaT\TableRelations;
use CaT\Filter as Filter;
class SqlQueryInterpreter {

	public function __construct(Filter\SqlPredicateInterpreter $predicate_interpreter, Filter\PredicateFactory $pf, \ilDB $ildb) {
		$this->predicate_interpreter = $predicate_interpreter;
		$this->gIldb = $ildb;
		$this->pf = $pf;
	}

	/**
	 * Get the data corresponding to query object.
	 *
	 * @param	Tables\AbstractQuery	$query
	 * @return	array[]
	 */
	public function interpret(Tables\AbstractQuery $query) {
		$res = $this->gIldb->query($this->getSql($query));
		$data = array();
		while($rec = $this->gIldb->fetchAssoc($res)) {
			$data[] = $rec;
		}
		return $data;
	}

	protected function requested(Tables\AbstractQuery $query) {
		$sql_requested = array();
		foreach ($query->requested() as $id => $field) {
			$sql_requested[] = $this->interpretField($field)." AS ".$id;
		}
		return implode(", ", $sql_requested);
	}


	protected function interpretField(Filter\Predicates\Field $field) {
			if($field instanceof Tables\TableField) {
				return $field->name();
			} elseif($field instanceof Tables\DerivedField)  {
				$sub_fields = array_map(array($this, 'interpretField'), $field->derivedFrom());
				return call_user_func_array($field->postprocess(), $sub_fields);
			} else {
				throw new TableRelationsException("Unknown field ".$field->name());
			}

	}

	/**
	 * Get the sql query corresponding to query object.
	 *
	 * @param	Tables\AbstractQuery	$query
	 * @return	string
	 */
	public function getSql($query) {
		return 
			"SELECT ".$this->requested($query).PHP_EOL
				.$this->from($query).PHP_EOL
				.$this->join($query).PHP_EOL
				.$this->where($query).PHP_EOL
				.$this->groupBy($query).PHP_EOL
				.$this->having($query).PHP_EOL
				.$this->orderBy($query);
	}

	protected function orderBy($query) {
		return ' ORDER BY '.implode(', ',$query->orderByFields()).' '.$query->orderByMode();
	}

	protected function interpretTable(Tables\AbstractTable $table) {
		if($table instanceof Tables\Table) {
			return $table->title()." AS ".$table->id();
		} elseif($table instanceof Tables\DerivedTable) {
			return $this->interpretDerivedTable($table);
		} else {
			throw new TableRelationsException();
		}
	}

	protected function from(Tables\AbstractQuery $query) {
		return " FROM ".$this->interpretTable($query->rootTable());
	}

	protected function interpretDerivedTable(Tables\DerivedTable $table) {
		return "(".$this->getSql($table->space->query()).") AS ".$table->id();
	}

	protected function interpretPredicate(Filter\Predicates\Predicate $predicate) {
		return $this->predicate_interpreter->interpret($predicate);
	}

	protected function join(Tables\AbstractQuery $query) {
		$joins = array();
		foreach($query as $table_id => $table) {
			$join = $this->interpretTable($table);
			$join_conditions = $query->currentJoinCondition();
			if(current($join_conditions) instanceof Tables\TableLeftJoin) {
				$join = " LEFT JOIN ".$join;
			} elseif(current($join_conditions) instanceof Tables\TableJoin) {
				$join = " JOIN ".$join;
			} else {
				throw new TableRelationsException("dunno condition");
			}
			$condition_aggregate = call_user_func_array(array($this->pf,"_ALL"),
				array_map(function ($condition) {return $condition->dependencyCondition();},$join_conditions));
			if($table->constrain()) {
				$condition_aggregate = $condition_aggregate->_AND($table->constrain());
			}
			$joins[] = $join." ON ".$this->interpretPredicate($condition_aggregate);
		}
		return count($joins) > 0 ? implode(PHP_EOL,$joins) : "";
	}

	protected function where(Tables\AbstractQuery $query) {
		$predicate = null;
		$root_constrain = $query->rootTable()->constrain();
		if($query->filter()) {
			$predicate = $query->filter();
			if($root_constrain) {
				$predicate = $predicate->_AND($root_constrain);
			}
			return "WHERE ".$this->interpretPredicate($predicate);
		} elseif( $root_constrain) {
			return "WHERE ".$this->interpretPredicate($root_constrain);
		}
		return "";
	}

	protected function having(Tables\AbstractQuery $query) {
		if($query->having()) {
			return " HAVING ".$this->interpretPredicate($query->having());
		}
		return "";
	}


	protected function groupBy(Tables\AbstractQuery $query) {
		$group_by = array();
		foreach($query->groupBy() as $field) {
			$group_by[] = $field->name();
		}
		return "GROUP BY ".implode(", ",$group_by);
	}
};