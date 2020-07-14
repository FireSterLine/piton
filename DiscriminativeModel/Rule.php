<?php

/*
 * Interface for rules
 */
abstract class Rule {
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
class RipperRule extends Rule {

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
    foreach ($this->antecedents as &$antecedent) {
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

        echo "\nOne condition: size = " . $growData->sumOfWeights() . PHP_EOL;

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
                . $antd->getAccuRate() . "=" . $antd->getAccu() . "/"
                . $antd->getCover() . " def. accuracy: $defAcRt"
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
    echo $this->toString() . PHP_EOL;
    
    $sumOfWeights = $pruneData->sumOfWeights();
    if (!($sumOfWeights > 0.0)) {
      return;
    }

    /* The default accurate # and rate on pruning data */
    $defAccu = $this->computeDefAccu($pruneData);

    echo "Pruning with " . $defAccu . " positive data out of "
        . $sumOfWeights . " instances";

    $size = $this->size();
    if ($size == 0) {
      return; // Default rule before pruning
    }

    ...
    double[] worthRt = new double[size];
    double[] coverage = new double[size];
    double[] worthValue = new double[size];
    for (int w = 0; w < size; w++) {
      worthRt[w] = coverage[w] = worthValue[w] = 0.0;
    }

    /* Calculate accuracy parameters for all the antecedents in this rule */
    double tn = 0.0; // True negative if useWhole
    for (int x = 0; x < size; x++) {
      Antd antd = m_Antds.get(x);
      Instances newData = pruneData;
      pruneData = new Instances(newData, 0); // Make data empty

      for (int y = 0; y < newData.numInstances(); y++) {
        Instance ins = newData.getInstance(y);

        if (antd.covers(ins)) { // Covered by this antecedent
          coverage[x] += ins.weight();
          pruneData.add(ins); // Add to data for further pruning
          if ((int) ins.classValue() == (int) m_Consequent) {
            worthValue[x] += ins.weight();
          }
        } else if (useWhole) { // Not covered
          if ((int) ins.classValue() != (int) m_Consequent) {
            tn += ins.weight();
          }
        }
      }

      if (useWhole) {
        worthValue[x] += tn;
        worthRt[x] = worthValue[x] / $sumOfWeights;
      } else {
        worthRt[x] = (worthValue[x] + 1.0) / (coverage[x] + 2.0);
      }
    }

    double maxValue = (defAccu + 1.0) / ($sumOfWeights + 2.0);
    int maxIndex = -1;
    for (int i = 0; i < worthValue.length; i++) {
      if (m_Debug) {
        double denom = useWhole ? $sumOfWeights : coverage[i];
        System.err.println(i + "(useAccuray? " + !useWhole + "): "
          + worthRt[i] + "=" + worthValue[i] + "/" + denom);
      }
      if (worthRt[i] > maxValue) { // Prefer to the
        maxValue = worthRt[i]; // shorter rule
        maxIndex = i;
      }
    }

    /* Prune the antecedents according to the accuracy parameters */
    for (int z = size - 1; z > maxIndex; z--) {
      m_Antds.remove(z);
    }
  }

  /**
   * Removes redundant tests in the rule.
   *
   * @param data an instance object that contains the appropriate header information for the attributes.
   */
  function cleanUp(&$data) {
    echo "RipperRule->cleanUp(" . get_var_dump($data) . ")" . PHP_EOL;
    $mins = array_fill(0,$data->numAttributes(),INF);
    $maxs = array_fill(0,$data->numAttributes(),-INF);
    
    for ($i = $this->size() - 1; $i >= 0; $i--) {
      // TODO maybe at some point this won't be necessary, and I'll directly use attr indices?
      $j = $this->antecedents[$i]->getAttribute()->getIndex();
      if ($this->antecedents[$i] instanceof ContinuousAntecedent) {
        $splitPoint = $this->antecedents[$i]->getSplitPoint();
        if ($this->antecedents[$i]->getValue() == 0) {
          if ($splitPoint < $mins[$attribute_idx]) {
            $mins[$attribute_idx] = $splitPoint;
          } else {
            array_splice($this->antecedents, $i, $i+1);
          }
        } else {
          if ($splitPoint > $maxs[$attribute_idx]) {
            $maxs[$attribute_idx] = $splitPoint;
          } else {
            array_splice($this->antecedents, $i, $i+1);
          }
        }
      }
    }
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