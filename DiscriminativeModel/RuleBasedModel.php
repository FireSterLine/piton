<?php

include "Antecedent.php";
include "Rule.php";
include "RuleStats.php";

/*
 * Interface for a generic discriminative model
 */
abstract class DiscriminativeModel {

  static $prefix = "models__";
  static $indexTableName = "models__index";

  abstract function fit(Instances &$data, Learner &$learner);
  abstract function predict(Instances $testData);

  abstract function save(string $path);
  abstract function load(string $path);

  static function loadFromFile(string $path) : DiscriminativeModel {
    if (DEBUGMODE > 2) echo "DiscriminativeModel::loadFromFile($path)" . PHP_EOL;
    postfixisify($path, ".mod");

    $str = file_get_contents($path);
    $obj_str = strtok($str, "\n");
    switch ($obj_str) {
      case "RuleBasedModel":
        $model = new RuleBasedModel();
        $model->load($path);
        break;

      default:
        die_error("Unknown model type in DiscriminativeModel::loadFromFile(\"$path\")" . $obj_str);
        break;
    }
    return $model;
  }

  /* Save model to database */
  function dumpToDB(object &$db, string $tableName) {
    //if (DEBUGMODE > 2) 
      echo "DiscriminativeModel->dumpToDB($tableName)" . PHP_EOL;

    $tableName = self::$prefix . $tableName;
    
    $sql = "DROP TABLE IF EXISTS `{$tableName}_dump`";

    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();

    $sql = "CREATE TABLE `{$tableName}_dump` (dump LONGTEXT)";

    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();

    $sql = "INSERT INTO `{$tableName}_dump` VALUES (?)";

    echo "SQL: $sql" . PHP_EOL;
    $stmt = $db->prepare($sql);
    $dump = serialize($this);
    // echo $dump;
    $stmt->bind_param("s", $dump);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();
    
  }

  function &LoadFromDB(object &$db, string $tableName) {
    if (DEBUGMODE > 2) echo "DiscriminativeModel->LoadFromDB($tableName)" . PHP_EOL;
    
    $tableName = self::$prefix . $tableName;
    
    $sql = "SELECT dump FROM " . $tableName . "_dump";
    echo "SQL: $sql" . PHP_EOL;
    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $res = $stmt->get_result();
    $stmt->close();
    
    if (!($res !== false))
      die_error("SQL query failed: $sql");
    if($res->num_rows !== 1) {
      die_error("Error reading RuleBasedModel table dump.");
    }
    $obj = unserialize($res->fetch_assoc()["dump"]);
    return $obj;
  }

  /* Print a textual representation of the rule */
  abstract function __toString () : string;
}

/*
 * This class represents a propositional rule-based model.
 */
class RuleBasedModel extends DiscriminativeModel {

  /* The set of rules */
  private $rules;
  
  /* The set of attributes which the rules refer to */
  private $attributes;
  
  function __construct() {
    if (DEBUGMODE > 2) echo "RuleBasedModel()" . PHP_EOL;
    $this->rules = NULL;
    $this->attributes = NULL;
  }

  /* Train the model using an optimizer */
  function fit(Instances &$trainData, Learner &$learner) {
    if (DEBUGMODE > 2) echo "RuleBasedModel->fit([trainData], " . get_class($learner) . ")" . PHP_EOL;
    $learner->teach($this, $trainData);
  }

  /* Perform prediction onto some data. */
  function predict(Instances $testData, bool $returnClassIndices = false) : array {
    if (DEBUGMODE > 2) echo "RuleBasedModel->predict(" . $testData->toString(true) . ")" . PHP_EOL;

    if (!(is_array($this->rules)))
      die_error("Can't use uninitialized rule-based model.");

    if (!(count($this->rules)))
      die_error("Can't use empty set of rules in rule-based model.");

    /* Extract the data in the same form that was seen during training */
    $testData = clone $testData;
    $testData->sortAttrsAs($this->attributes);

    /* Predict */
    $classAttr = $testData->getClassAttribute();
    $predictions = [];
    if (DEBUGMODE > 1) {
      echo "rules:\n";
      foreach ($this->rules as $r => $rule) {
        echo $rule->toString();
        echo "\n";
      }
    }
    if (DEBUGMODE > 1) echo "testing:\n";
    for ($x = 0; $x < $testData->numInstances(); $x++) {
      if (DEBUGMODE > 1) echo "[$x] : " . $testData->inst_toString($x);
      foreach ($this->rules as $r => $rule) {
        if ($rule->covers($testData, $x)) {
          if (DEBUGMODE > 1) echo $r;
          $idx = $rule->getConsequent();
          if ($returnClassIndices)
            $predictions[] = $classAttr->reprVal($idx);
          else
            $predictions[] = $idx;
          break;
        }
      }
      if (DEBUGMODE > 1) echo "\n";
    }

    if (count($predictions) != $testData->numInstances())
      die_error("Couldn't perform predictions for some instances (" .
        count($predictions) . "/" . $testData->numInstances() . " performed)");

    return $predictions;
  }

  /* Save model to file */
  function save(string $path) {
    if (DEBUGMODE > 2) echo "RuleBasedModel->save($path)" . PHP_EOL;
    postfixisify($path, ".mod");

    // $obj_repr = ["rules" => [], "attributes" => []];
    // foreach ($this->rules as $rule) {
    //   $obj_repr["rules"][] = $rule->serialize();
    // }
    // foreach ($this->attributes as $attribute) {
    //   $obj_repr["attributes"][] = $attribute->serialize();
    // }

    // file_put_contents($path, json_encode($obj_repr));
    $obj_repr = ["rules" => $this->rules, "attributes" => $this->attributes];
    file_put_contents($path, "RuleBasedModel\n" . serialize($obj_repr));
  }
  function load(string $path) {
    if (DEBUGMODE > 2) echo "RuleBasedModel->load($path)" . PHP_EOL;
    postfixisify($path, ".mod");
    // $obj_repr = json_decode(file_get_contents($path));

    // $this->rules = [];
    // $this->attributes = [];
    // foreach ($obj_repr["rules"] as $rule_repr) {
    //   $this->rules[] = Rule::createFromSerial($rule_repr);
    // }
    // foreach ($obj_repr["attributes"] as $attribute_repr) {
    //   $this->attributes[] = Attribute::createFromSerial($attribute_repr);
    // }
    $str = file_get_contents($path);
    $obj_str = strtok($str, "\n");
    $obj_str = strtok("\n");
    $obj_repr = unserialize($obj_str);
    $this->rules = $obj_repr["rules"];
    $this->attributes = $obj_repr["attributes"];
  }

  // Test a model TODO explain
  function test(Instances $testData) : array {
    echo "RuleBasedModel->test(" . $testData->toString(true) . ")" . PHP_EOL;

    $ground_truths = [];
    
    for ($x = 0; $x < $testData->numInstances(); $x++) {
      $ground_truths[] = $testData->inst_classValue($x);
    }

    // $testData->dropOutputAttr();
    $predictions = $this->predict($testData, true);
    
    // echo "\$ground_truths : " . get_var_dump($ground_truths) . PHP_EOL;
    // echo "\$predictions : " . get_var_dump($predictions) . PHP_EOL;
    if (DEBUGMODE > 1) {
      echo "ground_truths,predictions:" . PHP_EOL;
      foreach ($ground_truths as $i => $val) {
        echo "[" . $val . "," . $predictions[$i] . "]";
      }
    }

    $classAttr = $testData->getClassAttribute();

    $domain = $classAttr->getDomain();
    if ( count($domain) != 2) {
      $measures = [];
      foreach ($domain as $classId => $className) {
        $measures[$classId] = $this->computeMeasures($ground_truths, $predictions, $classId);
      }
    }
    else {
      // For the binary case, one measure for YES class is enough
      $measures = [$this->computeMeasures($ground_truths, $predictions, 1)];
    }
    return $measures;
  }

  function computeMeasures(array $ground_truths, array $predictions, int $classId) : array {
    $positives = 0;
    $negatives = 0;
    $TP = 0;
    $TN = 0;
    $FP = 0;
    $FN = 0;
    if (DEBUGMODE > 1) echo "\n";
    foreach ($ground_truths as $i => $val) {
      if ($ground_truths[$i] == $classId) {
        $positives++;

        if ($ground_truths[$i] == $predictions[$i]) {
          $TP++;
        } else {
          $FN++;
        }
      }
      else {
        $negatives++;

        if ($ground_truths[$i] == $predictions[$i]) {
          $TN++;
        } else {
          $FP++;
        }
      }
    }
    $accuracy = safe_div(($TP+$TN), ($positives+$negatives));
    $sensitivity = safe_div($TP, $positives);
    $specificity = safe_div($TN, $negatives);
    $PPV = safe_div($TP, ($TP+$FP));
    $NPV = safe_div($TN, ($TN+$FN));
    
    // if (DEBUGMODE) echo "\$positives    : $positives    " . PHP_EOL;
    // if (DEBUGMODE) echo "\$negatives    : $negatives " . PHP_EOL;
    // if (DEBUGMODE) echo "\$TP           : $TP " . PHP_EOL;
    // if (DEBUGMODE) echo "\$TN           : $TN " . PHP_EOL;
    // if (DEBUGMODE) echo "\$FP           : $FP " . PHP_EOL;
    // if (DEBUGMODE) echo "\$FN           : $FN " . PHP_EOL;
    // if (DEBUGMODE) echo "\$accuracy     : $accuracy " . PHP_EOL;
    // if (DEBUGMODE) echo "\$sensitivity  : $sensitivity " . PHP_EOL;
    // if (DEBUGMODE) echo "\$specificity  : $specificity " . PHP_EOL;
    // if (DEBUGMODE) echo "\$PPV          : $PPV " . PHP_EOL;
    // if (DEBUGMODE) echo "\$NPV          : $NPV " . PHP_EOL;

    return [
      $positives, $negatives,
      $TP, $TN, $FP, $FN,
      $accuracy,
      $sensitivity, $specificity, $PPV, $NPV];
  }

  /* Save model to database */
  function saveToDB(object &$db, string $modelName, string $tableName, ?Instances &$testData = NULL) {
    //if (DEBUGMODE > 2) 
      echo "RuleBasedModel->saveToDB($tableName)" . PHP_EOL;
    
    if ($testData !== NULL) {
      $testData = clone $testData;
      $testData->sortAttrsAs($this->attributes);
    }
    
    $tableName = self::$prefix . $tableName;

    $sql = "DROP TABLE IF EXISTS `$tableName`";

    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();

    $sql = "CREATE TABLE `$tableName`";
    $sql .= " (ID INT AUTO_INCREMENT PRIMARY KEY, class VARCHAR(256) NOT NULL, rule TEXT NOT NULL, support FLOAT DEFAULT NULL, confidence FLOAT DEFAULT NULL, lift FLOAT DEFAULT NULL, conviction FLOAT DEFAULT NULL)";
    // $sql .= "(class VARCHAR(256) PRIMARY KEY, regola TEXT)"; TODO why primary

    echo "SQL: $sql" . PHP_EOL;
    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();

    $arr_vals = [];
    foreach ($this->rules as $rule) {
      echo $rule->toString($this->getClassAttribute()) . PHP_EOL;
      $antds = [];
      foreach ($rule->getAntecedents() as $antd) {
        $antds[] = $antd->serialize();
      }
      $str = "\"" .
           strval($this->getClassAttribute()->reprVal($rule->getConsequent()))
            . "\", \"" . join(" AND ", $antds) . "\"";

      if ($testData !== NULL) {

        $measures = $rule->computeMeasures($testData);
        $str .= "," . join(",", array_map("mysql_number", $measures));
      }
      $arr_vals[] = $str;
    }

    $sql = "INSERT INTO `$tableName`";
    if ($testData === NULL) {
      $sql .= " (class, rule)";
    } else {
      $sql .= " (class, rule, support, confidence, lift, conviction)";
    }
    $sql .= " VALUES (" . join("), (", $arr_vals) . ")";
    
    echo "SQL: $sql" . PHP_EOL;
    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();

    // foreach ($this->attributes as $attribute) {
    //   echo $attribute->toString(false) . PHP_EOL;
    // }

    $sql = "CREATE TABLE IF NOT EXISTS `" . self::$indexTableName . "`";
    $sql .= " (ID INT AUTO_INCREMENT PRIMARY KEY, date DATETIME NOT NULL, modelName TEXT NOT NULL, tableName TEXT NOT NULL, classId INT NOT NULL, className TEXT NOT NULL";
    $sql .= ", positives FLOAT DEFAULT NULL";
    $sql .= ", negatives FLOAT DEFAULT NULL";
    $sql .= ", TP FLOAT DEFAULT NULL";
    $sql .= ", TN FLOAT DEFAULT NULL";
    $sql .= ", FP FLOAT DEFAULT NULL";
    $sql .= ", FN FLOAT DEFAULT NULL";
    $sql .= ", accuracy FLOAT DEFAULT NULL";
    $sql .= ", sensitivity FLOAT DEFAULT NULL";
    $sql .= ", specificity FLOAT DEFAULT NULL";
    $sql .= ", PPV FLOAT DEFAULT NULL";
    $sql .= ", NPV FLOAT DEFAULT NULL";
    $sql .= ")";

    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();

    // $globalIndicatorsStr = "";
    // $globalIndicatorsStr .= "positives    : $positives\n";
    // $globalIndicatorsStr .= "negatives    : $negatives\n";
    // $globalIndicatorsStr .= "TP           : $TP\n";
    // $globalIndicatorsStr .= "TN           : $TN\n";
    // $globalIndicatorsStr .= "FP           : $FP\n";
    // $globalIndicatorsStr .= "FN           : $FN\n";
    // $globalIndicatorsStr .= "accuracy     : $accuracy\n";
    // $globalIndicatorsStr .= "sensitivity  : $sensitivity\n";
    // $globalIndicatorsStr .= "specificity  : $specificity\n";
    // $globalIndicatorsStr .= "PPV          : $PPV\n";
    // $globalIndicatorsStr .= "NPV          : $NPV\n";

    $sql = "INSERT INTO `" . self::$indexTableName . "`";
    $sql .= " (date, modelName, tableName, classId, className";
    if ($testData !== NULL) {
      $sql .= ", positives, negatives, TP, TN, FP, FN, accuracy, sensitivity, specificity, PPV, NPV";
    }
    $sql .= ") VALUES (";

    $valuesSql = [];
    if ($testData !== NULL) {
      $measures = $this->test($testData);
    }
    $classAttr = $this->getClassAttribute();
    foreach ($classAttr->getDomain() as $classId => $className) {
      $valueSql = "'" . date('Y-m-d H:i:s') . "', '$modelName', '$tableName', $classId, '$className'";

      if ($testData !== NULL) {
        $valueSql .= ", " . join(", ", array_map("mysql_number", $measures[$classId]));
      }
      $valuesSql[] = $valueSql;
    }
    $sql .= join("), (", $valuesSql) . ")";

    echo "SQL: $sql" . PHP_EOL;
    $stmt = $db->prepare($sql);
    if (!$stmt)
      die_error("Incorrect SQL query: $sql");
    if (!$stmt->execute())
      die_error("Query failed: $sql");
    $stmt->close();
    // For reference, to read the comment:
    // SELECT table_comment 
    // FROM INFORMATION_SCHEMA.TABLES 
    // WHERE table_schema='my_cool_database' 
    //     AND table_name='$tableName';

  }

  // function LoadFromDB(object &$db, string $tableName) {
  //   if (DEBUGMODE > 2) echo "RuleBasedModel->LoadFromDB($tableName)" . PHP_EOL;
  //   prefixisify($tableName, prefix);
    
  //   // $sql = "SELECT class, rule, relevance, confidence, lift, conviction FROM $tableName";
  //   $sql = "SELECT dump FROM " . $tableName . "_dump";
  //   echo "SQL: $sql" . PHP_EOL;
  //   $stmt = $this->db->prepare($sql);
  //   if (!$stmt)
  //     die_error("Incorrect SQL query: $sql");
    // if (!$stmt->execute())
    //   die_error("Query failed: $sql");
    // $stmt->close();
  //   $res = $stmt->get_result();
  //   if (!($res !== false))
  //     die_error("SQL query failed: $sql");
  //   if(count($res) !== 1) {
  //     die_error("Error reading RuleBasedModel table dump.");
  //   }
  //   $obj_repr = unserialize($res[0]);
  //   $this->rules = $obj_repr["rules"];
  //   $this->attributes = $obj_repr["attributes"];
  // }

  public function getAttributes() : array
  {
    return $this->attributes;
  }

  public function setAttributes(array $attributes) : self
  {
    $this->attributes = $attributes;
    return $this;
  }

  function getClassAttribute() : _Attribute {
    // Note: assuming the class attribute is the first
    return $this->getAttributes()[0];
  }

  public function getRules() : array
  {
    return $this->rules;
  }

  public function setRules(array $rules) : self
  {
    $this->rules = $rules;
    return $this;
  }

  public function resetRules()
  {
    return $this->setRules([]);
  }

  /* Print a textual representation of the rule */
  function __toString () : string {
    $out_str = "";
    $out_str .= "RuleBasedModel with rules & attributes: " . PHP_EOL;
    foreach ($this->getRules() as $x => $rule) {
      $out_str .= $x . ": " . $rule->toString() . PHP_EOL;
    }
    foreach ($this->getAttributes() as $x => $attr) {
      $out_str .= $x . ": " . $attr->toString() . PHP_EOL;
    }
    return $out_str;
  }
}


?>