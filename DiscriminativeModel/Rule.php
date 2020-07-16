<?php

/*
 * Interface for rules
 */
abstract class _Rule {
  /** The internal representation of the class label to be predicted */
  protected $consequent;

  /** The vector of antecedents of this rule */
  protected $antecedents;

  /** Constructor */
  function __construct() {
    $this->consequent = -1;
    $this->antecedents = [];
  }

  /**
   * @return mixed
   */
  public function getConsequent()
  {
      return $this->consequent;
  }

  /**
   * @param mixed $consequent
   *
   * @return self
   */
  public function setConsequent($consequent)
  {
      $this->consequent = $consequent;

      return $this;
  }

  /**
   * @return mixed
   */
  public function getAntecedents()
  {
      return $this->antecedents;
  }

  /**
   * @param mixed $antecedents
   *
   * @return self
   */
  public function setAntecedents($antecedents)
  {
      $this->antecedents = $antecedents;

      return $this;
  }
}

/**
 * A single rule that predicts specified class.
 * 
 * A rule consists of antecedents "AND"-ed together and the consequent (class
 * value) for the classification. In this class, the Information Gain
 * (p*[log(p/t) - log(P/T)]) is used to select an antecedent and Reduced Error
 * Prunning (REP) with the metric of accuracy rate p/(p+n) or (TP+TN)/(P+N) is
 * used to prune the rule.
 */
class RipperRule extends _Rule {

  /**
   * Whether the instance covered by this rule.
   * Note that an empty rule covers everything.
   * 
   * @param datum the instance in question
   * @return the boolean value indicating whether the instance is covered by
   *         this rule
   */
  function covers(&$data, $i) {
    $isCover = true;

    for ($x = 0; $x < $this->size(); $x++) {
      if (!$this->antecedents[$x]->covers($data, $i)) {
        $isCover = false;
        break;
      }
    }
    return $isCover;
  }

  function coversAll(&$data) {
    $isCover = true;

    for ($i = 0; $i < $data->numInstances(); $i++) {
      if (!$this->covers($data, $i)) {
        $isCover = false;
        break;
      }
    }
    return $isCover;
  }

  /**
   * Whether this rule has antecedents, i.e. whether it is a default rule
   * 
   * @return the boolean value indicating whether the rule has antecedents
   */
  function hasAntecedents() {
    return ($this->antecedents !== NULL && $this->size() > 0);
  }

  function hasConsequent() {
    return ($this->consequent !== NULL && $this->consequent !== -1);
  }

  /**
   * the number of antecedents of the rule
   * 
   * @return the size of this rule
   */
  function size() {
    return count($this->antecedents);
  }

  /**
   * Private function to compute default number of accurate instances in the
   * specified data for the consequent of the rule
   * 
   * @param data the data in question
   * @return the default accuracy number
   */
  function computeDefAccu(&$data) {
    echo "RipperRule->computeDefAccu(&[data])" . PHP_EOL;
    $defAccu = 0;
    for ($i = 0; $i < $data->numInstances(); $i++) {
      if ($data->inst_classValue($i) == $this->consequent) {
        $defAccu += $data->inst_weight($i);
      }
    }
    echo "\$defAccu : $defAccu" . PHP_EOL;
    return $defAccu;
  }


  /**
   * Compute the best information gain for the specified antecedent
   * 
   * @param instances the data based on which the infoGain is computed
   * @param defAcRt the default accuracy rate of data
   * @param antd the specific antecedent
   * @return the data covered by the antecedent
   */
  private function computeInfoGain(Instances &$data, float $defAcRt,
    _Antecedent $antd) {

    /*
     * Split the data into bags. The information gain of each bag is also
     * calculated in this procedure
     */
    $splitData = $antd->splitData($data, $defAcRt, $this->consequent);

    /* Get the bag of data to be used for next antecedents */
    if ($splitData != NULL) {
      return $splitData[$antd->getValue()];
    } else {
      return NULL;
    }
  }

  /**
   * Build one rule using the growing data
   * 
   * @param data the growing data used to build the rule
   */
  function grow(Instances &$growData, float $minNo) {
    echo "RipperRule->grow(&[growData])" . PHP_EOL;
    echo $this->toString() . PHP_EOL;
    
    if (!$this->hasConsequent()) {
      throw new Exception(" Consequent not set yet.");
    }

    $sumOfWeights = $growData->sumOfWeights();
    if (!($sumOfWeights > 0.0)) {
      return;
    }

    /* Compute the default accurate rate of the growing data */
    $defAccu = $this->computeDefAccu($growData);
    $defAcRt = ($defAccu + 1.0) / ($sumOfWeights + 1.0);

    /* Keep the record of which attributes have already been used */
    $used = array_fill(0, $growData->numAttributes(), false);
    $numUnused = count($used);

    // If there are already antecedents existing
    foreach ($this->antecedents as $antecedent) {
      if (!($antecedent instanceof ContinuousAntecedent)) {
        $used[$antecedent->getAttribute()->getIndex()] = true;
        $numUnused--;
      }
    }

    while ($growData->numInstances() > 0
      && $numUnused > 0
      && $defAcRt < 1.0) {

      // We require that infoGain be positive
      /*
       * if(numAntds == originalSize) maxInfoGain = 0.0; // At least one
       * condition allowed else maxInfoGain = Utils.eq(defAcRt, 1.0) ?
       * defAccu/(double)numAntds : 0.0;
       */

      /* Build a list of antecedents */
      $maxInfoGain = 0.0;
      $maxAntd = NULL;
      $maxCoverData = NULL;

      /* Build one condition based on all attributes not used yet */
      foreach ($growData->getAttributes(false) as $attr) {

        echo "\nAttribute '{$attr->toString()}'. (total weight = " . $growData->sumOfWeights() . ")" . PHP_EOL;

        $antd = _Antecedent::createFromAttribute($attr);

        if (!$used[$attr->getIndex()]) {
          /*
           * Compute the best information gain for each attribute, it's stored
           * in the antecedent formed by this attribute. This procedure
           * returns the data covered by the antecedent
           */
          $coverData = $this->computeInfoGain($growData, $defAcRt, $antd);
          if ($coverData != NULL) {
            $infoGain = $antd->getMaxInfoGain();

            if ($infoGain > $maxInfoGain) {
              $maxAntd      = $antd;
              $maxCoverData = $coverData;
              $maxInfoGain  = $infoGain;
            }
            echo "Test of {" . $antd->toString()
                . "}:\n\tinfoGain = " . $infoGain . " | Accuracy = "
                . $antd->getAccuRate()*100 . "% = " . $antd->getAccu() . "/"
                . $antd->getCover() . " | def. accuracy: $defAcRt"
                . "\n\tmaxInfoGain = " . $maxInfoGain . PHP_EOL;

          }
        }
      }

      if ($maxAntd === NULL) {
        break; // Cannot find antds
      }
      if ($maxAntd->getAccu() < $minNo) {
        break;// Too low coverage
      }

      // Numeric attributes can be used more than once
      if (!($maxAntd instanceof ContinuousAntecedent)) {
        $used[$maxAntd->getAttribute()->getIndex()] = true;
        $numUnused--;
      }

      $this->antecedents[] = $maxAntd;
      $growData = $maxCoverData;// Grow data size is shrinking
      $defAcRt = $maxAntd->getAccuRate();
    }
    echo $this->toString() . PHP_EOL;
  }


  /**
   * Prune all the possible final sequences of the rule using the pruning
   * data. The measure used to prune the rule is based on flag given.
   * 
   * @param pruneData the pruning data used to prune the rule
   * @param useWhole flag to indicate whether use the error rate of the whole
   *          pruning data instead of the data covered
   */
  function prune(Instances &$pruneData, bool $useWhole) {
    echo "RipperRule->grow(&[growData])" . PHP_EOL;
    echo "Rule: " . $this->toString() . PHP_EOL;
    echo "Data: " . $pruneData->toString() . PHP_EOL;
    
    $sumOfWeights = $pruneData->sumOfWeights();
    if (!($sumOfWeights > 0.0)) {
      return;
    }

    /* The default accurate # and rate on pruning data */
    $defAccu = $this->computeDefAccu($pruneData);

    echo "Pruning with " . $defAccu . " positive data out of "
        . $sumOfWeights . " instances" . PHP_EOL;

    $size = $this->size();
    if ($size == 0) {
      return; // Default rule before pruning
    }

    $worthRt    = array_fill(0, $size, 0.0);
    $coverage   = array_fill(0, $size, 0.0);
    $worthValue = array_fill(0, $size, 0.0);

    /* Calculate accuracy parameters for all the antecedents in this rule */
    echo "Calculating accuracy parameters for all the antecedents..." . PHP_EOL;

    $tn = 0.0; // True negative if useWhole
    foreach ($this->antecedents as $x => $antd) {
      $newData = clone $pruneData;
      $pruneData = Instances::createEmpty($newData); // Make data empty

      for ($y = 0; $y < $newData->numInstances(); $y++) {
        if ($antd->covers($newData, $y)) { // Covered by this antecedent
          $classValue = $newData->inst_classValue($y);
          $weight     = $newData->inst_weight($y);

          $coverage[$x] += $weight;
          $pruneData->pushInstance($newData->getInstance($y)); // Add to data for further pruning
          if ($classValue == $this->consequent) {
            $worthValue[$x] += $weight;
          }
        } else if ($useWhole) { // Not covered
          if ($classValue != $this->consequent) {
            $tn += $weight;
          }
        }
      }

      if ($useWhole) {
        $worthValue[$x] += $tn;
        $worthRt[$x] = $worthValue[$x] / $sumOfWeights;
      } else {
        $worthRt[$x] = ($worthValue[$x] + 1.0) / ($coverage[$x] + 2.0);
      }

      echo $antd->toString() . ": coverage=" . $coverage[$x] . ", worthValue=" . $worthValue[$x] . PHP_EOL;
    }

    $maxValue = ($defAccu + 1.0) / ($sumOfWeights + 2.0);
    echo "maxValue=$maxValue";
    $maxIndex = -1;
    for ($i = 0; $i < $size; $i++) {
      echo $i . " : (useAccuracy? " . !$useWhole . "): "
        . $worthRt[$i] . " ~= " . $worthValue[$i] . "/" . ($useWhole ? $sumOfWeights : $coverage[$i]) . PHP_EOL;
      // Prefer to the shorter rule
      if ($worthRt[$i] > $maxValue) {
        $maxValue = $worthRt[$i];
        $maxIndex = $i;
      }
    }

    /* Prune the antecedents according to the accuracy parameters */
    var_dump($maxIndex);
    var_dump($this->antecedents);
    for ($z = $size - 1; $z > $maxIndex; $z--) {
      array_splice($this->antecedents, $z, 1);
    }
    var_dump($this->antecedents);
    // TODO easier? array_splice($this->antecedents, $maxIndex + 1);
  }

  /**
   * Removes redundant tests in the rule.
   *
   * @param data an instance object that contains the appropriate header information for the attributes.
   */
  function cleanUp(&$data) {
    echo "RipperRule->cleanUp(&[data])" . PHP_EOL;
    echo "Rule: " . $this->toString() . PHP_EOL;
    echo "Data: " . $data->toString() . PHP_EOL;

    $mins = array_fill(0,$data->numAttributes(),INF);
    $maxs = array_fill(0,$data->numAttributes(),-INF);
    
    for ($i = $this->size() - 1; $i >= 0; $i--) {
      var_dump($this->antecedents);
      $j = $this->antecedents[$i]->getAttribute()->getIndex();
      if ($this->antecedents[$i] instanceof ContinuousAntecedent) {
        $splitPoint = $this->antecedents[$i]->getSplitPoint();
        if ($this->antecedents[$i]->getValue() == 0) {
          if ($splitPoint < $mins[$j]) {
            $mins[$j] = $splitPoint;
          } else {
            array_splice($this->antecedents, $i, 1);
          }
        } else {
          if ($splitPoint > $maxs[$j]) {
            $maxs[$j] = $splitPoint;
          } else {
            array_splice($this->antecedents, $i, 1);
          }
        }
      }
    }
  }
  
  function __clone()
  {
      $this->antecedents = array_map("clone", $this->antecedents);
  }

  /* Print a textual representation of the antecedent */
  function toString(_Attribute $classAttr = NULL) {
    $ants = [];
    if ($this->hasAntecedents()) {
      for ($j = 0; $j < $this->size(); $j++) {
        $ants[] = "(" . $this->antecedents[$j]->toString(true) . ")";
      }
    }

    if ($classAttr === NULL) {
      $out_str = "( " . join($ants, " and ") . " ) => [{$this->consequent}]";
    }
    else {
      $out_str = "( " . join($ants, " and ") . " ) => " . $classAttr->getName() . "=" . $classAttr->reprVal($this->consequent);
    }

    return $out_str;
  }
}

?>