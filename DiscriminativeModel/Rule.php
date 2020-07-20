<?php

/**
 * A single rule that predicts specified class.
 * 
 * A rule consists of antecedents "AND"-ed together and the consequent (class
 * value) for the classification.
 */
abstract class _Rule {
  /** The internal representation of the class label to be predicted */
  protected $consequent;

  /** The vector of antecedents of this rule */
  protected $antecedents;

  /** Constructor */
  function __construct(int $consequent = -1, array $antecedents = []) {
    $this->consequent = $consequent;
    $this->antecedents = $antecedents;
  }

  public function getConsequent() : int
  {
    return $this->consequent;
  }

  public function setConsequent(int $consequent) : self
  {
    $this->consequent = $consequent;
    return $this;
  }

  public function getAntecedents() : array
  {
    return $this->antecedents;
  }

  public function setAntecedents(array $antecedents) : self
  {
    $this->antecedents = $antecedents;
    return $this;
  }
}

/**
 * Rule class for RIPPER algorithm
 * 
 * In this class, the Information Gain
 * (p*[log(p/t) - log(P/T)]) is used to select an antecedent and Reduced Error
 * Prunning (REP) with the metric of accuracy rate p/(p+n) or (TP+TN)/(P+N) is
 * used to prune the rule.
 *
 */
class RipperRule extends _Rule {

  /** Constructor */
  function __construct(int $consequent) {
    if(!($consequent >= 0))
      die_error("Negative consequent ($consequent) found when building RipperRule");
    parent::__construct($consequent);
  }

  /**
   * Whether the instance is covered by this rule.
   * Note that an empty rule covers everything.
   * 
   * @param data the set of instances
   * @param i the index of the instance in question
   * @return the boolean value indicating whether the instance is covered by
   *         this rule
   */
  function covers(Instances &$data, int $i) : bool {
    $covers = true;

    foreach ($this->antecedents as $antd) {
      if (!$antd->covers($data, $i)) {
        $covers = false;
        break;
      }
    }
    return $covers;
  }

  function coversAll(Instances &$data) : bool {
    $covers = true;

    for ($i = 0; $i < $data->numInstances(); $i++) {
      if (!$this->covers($data, $i)) {
        $covers = false;
        break;
      }
    }
    return $covers;
  }

  /**
   * Whether this rule has antecedents, i.e. whether it is a "default rule"
   * 
   * @return the boolean value indicating whether the rule has antecedents
   */
  function hasAntecedents() : bool {
    return ($this->antecedents !== NULL && $this->getSize() > 0);
  }

  function hasConsequent() : bool {
    return ($this->consequent !== NULL && $this->consequent !== -1);
  }

  /**
   * the number of antecedents of the rule
   */
  function getSize() : int {
    return count($this->antecedents);
  }

  /**
   * Private function to compute default number of accurate instances in the
   * specified data for the consequent of the rule
   * 
   * @param data the data in question
   * @return the default accuracy number
   */
  function computeDefAccu(Instances &$data) : float {
    if (DEBUGMODE) echo "RipperRule->computeDefAccu(&[data])" . PHP_EOL;
    $defAccu = 0;
    for ($i = 0; $i < $data->numInstances(); $i++) {
      if ($data->inst_classValue($i) == $this->consequent) {
        $defAccu += $data->inst_weight($i);
      }
    }
    if (DEBUGMODE) echo "\$defAccu : $defAccu" . PHP_EOL;
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
    _Antecedent $antd) : ?Instances {

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
   * @param minNo minimum weight allowed within the split
   */
  function grow(Instances &$growData, float $minNo) {
    if (DEBUGMODE) echo "RipperRule->grow(&[growData])" . PHP_EOL;
    if (DEBUGMODE) echo $this->toString() . PHP_EOL;
    
    if (!$this->hasConsequent()) {
      throw new Exception(" Consequent not set yet.");
    }

    $sumOfWeights = $growData->getSumOfWeights();
    if (!($sumOfWeights > 0.0)) {
      return;
    }

    /* Compute the default accurate rate of the growing data */
    $defAccu = $this->computeDefAccu($growData);
    $defAcRt = ($defAccu + 1.0) / ($sumOfWeights + 1.0);

    /* Keep the record of which attributes have already been used */
    $used = array_fill(0, $growData->numAttributes(), false);
    $numUnused = count($used);

    /* If there are already antecedents existing */
    foreach ($this->antecedents as $antecedent) {
      if (!($antecedent instanceof ContinuousAntecedent)) {
        $used[$antecedent->getAttribute()->getIndex()] = true;
        $numUnused--;
      }
    }

    while ($growData->numInstances() > 0
      && $numUnused > 0
      && $defAcRt < 1.0) {

      /* Build a list of antecedents */
      $maxInfoGain = 0.0;
      $maxAntd = NULL;
      $maxCoverData = NULL;

      /* Build one condition based on all attributes not used yet */
      foreach ($growData->getAttributes(false) as $attr) {

        if (DEBUGMODE) echo "\nAttribute '{$attr->toString()}'. (total weight = " . $growData->getSumOfWeights() . ")" . PHP_EOL;

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
            if (DEBUGMODE) {
              echo "Test of {" . $antd->toString()
                . "}:\n\tinfoGain = " . $infoGain . " | Accuracy = "
                . $antd->getAccuRate()*100 . "% = " . $antd->getAccu() . "/"
                . $antd->getCover() . " | def. accuracy: $defAcRt"
                . "\n\tmaxInfoGain = " . $maxInfoGain . PHP_EOL;
            }
          }
        }
      }

      if ($maxAntd === NULL) {
        break; // Cannot find antds
      }
      if ($maxAntd->getAccu() < $minNo) {
        break;// Too low coverage
      }

      /* Numeric attributes can be used more than once */
      if (!($maxAntd instanceof ContinuousAntecedent)) {
        $used[$maxAntd->getAttribute()->getIndex()] = true;
        $numUnused--;
      }

      $this->antecedents[] = $maxAntd;
      $growData = $maxCoverData; // Grow data size shrinks
      $defAcRt = $maxAntd->getAccuRate();
    }
    if (DEBUGMODE) echo $this->toString() . PHP_EOL;
  }


  /**
   * Prune all the possible final sequences of the rule using the pruning
   * data. The measure used to prune the rule is based on the given flag.
   * 
   * @param pruneData the pruning data used to prune the rule
   * @param useWhole flag to indicate whether use the error rate of the whole
   *          pruning data instead of the data covered
   */
  function prune(Instances &$pruneData, bool $useWhole) {
    if (DEBUGMODE) echo "RipperRule->grow(&[growData])" . PHP_EOL;
    if (DEBUGMODE) echo "Rule: " . $this->toString() . PHP_EOL;
    if (DEBUGMODE) echo "Data: " . $pruneData->toString() . PHP_EOL;
    
    $sumOfWeights = $pruneData->getSumOfWeights();
    if (!($sumOfWeights > 0.0)) {
      return;
    }

    /* The default accurate # and rate on pruning data */
    $defAccu = $this->computeDefAccu($pruneData);

    if (DEBUGMODE) {
      echo "Pruning with " . $defAccu . " positive data out of "
        . $sumOfWeights . " instances" . PHP_EOL;
    }

    $size = $this->getSize();
    if ($size == 0) {
      return; // Default rule before pruning
    }

    $worthRt    = array_fill(0, $size, 0.0);
    $coverage   = array_fill(0, $size, 0.0);
    $worthValue = array_fill(0, $size, 0.0);

    /* Calculate accuracy parameters for all the antecedents in this rule */
    if (DEBUGMODE) echo "Calculating accuracy parameters for all the antecedents..." . PHP_EOL;

    // True negative used if $useWhole
    $tn = 0.0;
    foreach ($this->antecedents as $x => $antd) {
      $newData = $pruneData;
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

      if (DEBUGMODE) echo $antd->toString() . ": coverage=" . $coverage[$x] . ", worthValue=" . $worthValue[$x] . PHP_EOL;
    }

    $maxValue = ($defAccu + 1.0) / ($sumOfWeights + 2.0);
    if (DEBUGMODE) echo "maxValue=$maxValue";
    $maxIndex = -1;
    for ($i = 0; $i < $size; $i++) {
      if (DEBUGMODE) {
        echo $i . " : (useAccuracy? " . !$useWhole . "): "
        . $worthRt[$i] . " ~= " . $worthValue[$i] . "/" . ($useWhole ? $sumOfWeights : $coverage[$i]) . PHP_EOL;
      }
      // Prefer to the shorter rule
      if ($worthRt[$i] > $maxValue) {
        $maxValue = $worthRt[$i];
        $maxIndex = $i;
      }
    }

    /* Prune the antecedents according to the accuracy parameters */
    if (DEBUGMODE) var_dump("maxIndex " . $maxIndex . " " . count($this->antecedents));
    if (DEBUGMODE) var_dump($this->antecedents);
    array_splice($this->antecedents, $maxIndex + 1);
    if (DEBUGMODE) var_dump($this->antecedents);
  }

  /**
   * Removes redundant tests in the rule.
   *
   * @param data
   */
  function cleanUp(Instances &$data) {
    if (DEBUGMODE) echo "RipperRule->cleanUp(&[data])" . PHP_EOL;
    if (DEBUGMODE) echo "Rule: " . $this->toString() . PHP_EOL;
    if (DEBUGMODE) echo "Data: " . $data->toString() . PHP_EOL;

    $mins = array_fill(0,$data->numAttributes(),INF);
    $maxs = array_fill(0,$data->numAttributes(),-INF);
    
    for ($i = $this->getSize() - 1; $i >= 0; $i--) {
      if (DEBUGMODE) var_dump($this->antecedents);
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
    $this->antecedents = array_map("clone_object", $this->antecedents);
  }

  /* Print a textual representation of the rule */
  function __toString() : string {
    return $this->toString();
  }
  function toString(_Attribute $classAttr = NULL) : string {
    $ants = [];
    if ($this->hasAntecedents()) {
      for ($j = 0; $j < $this->getSize(); $j++) {
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