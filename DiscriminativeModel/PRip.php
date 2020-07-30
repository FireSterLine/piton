<?php

/*
 * Interface for learner/optimizers
 */
abstract class Learner {
  /* Returns an uninitialized DiscriminativeModel */
  abstract function initModel() : DiscriminativeModel;

  /* Trains a DiscriminativeModel */
  abstract function teach(DiscriminativeModel &$model, Instances $data);
}

/*
 * Repeated Incremental Pruning to Produce Error Reduction (RIPPER),
 * which was proposed by William W. Cohen as an optimized version of IREP. 
 *
 * The algorithm is briefly described as follows: 

 * Initialize RS = {}, and for each class from the less prevalent one to the more frequent one, DO: 

 * 1. Building stage:
 * Repeat 1.1 and 1.2 until the descrition length (DL) of the ruleset and examples
 *  is 64 bits greater than the smallest DL met so far,
 *  or there are no positive examples, or the error rate >= 50%. 

 * 1.1. Grow phase:
 * Grow one rule by greedily adding antecedents (or conditions) to the rule until the rule is perfect (i.e. 100% accurate).  The procedure tries every possible value of each attribute and selects the condition with highest information gain: p(log(p/t)-log(P/T)).

 * 1.2. Prune phase:
 * Incrementally prune each rule and allow the pruning of any final sequences of the antecedents;The pruning metric is (p-n)/(p+n) -- but it's actually 2p/(p+n) -1, so in this implementation we simply use p/(p+n) (actually (p+1)/(p+n+2), thus if p+n is 0, it's 0.5).

 * 2. Optimization stage:
 *  after generating the initial ruleset {Ri}, generate and prune two variants of each rule Ri from randomized data using procedure 1.1 and 1.2. But one variant is generated from an empty rule while the other is generated by greedily adding antecedents to the original rule. Moreover, the pruning metric used here is (TP+TN)/(P+N).Then the smallest possible DL for each variant and the original rule is computed.  The variant with the minimal DL is selected as the final representative of Ri in the ruleset.After all the rules in {Ri} have been examined and if there are still residual positives, more rules are generated based on the residual positives using Building Stage again. 
 * 3. Delete the rules from the ruleset that would increase the DL of the whole ruleset if it were in it. and add resultant ruleset to RS. 
 * ENDDO

 */
class PRip extends Learner {

  /*** Options that are useful during the training stage */

  /** The limit of description length surplus in ruleset generation */
  static private $MAX_DL_SURPLUS = 64.0;

  /* Whether to turn on the debug mode (Default: false) */
  private $debug;

  /** Number of runs of optimizations */
  private $numOptimizations;

  /** Randomization seed */
  private $seed;

  /** The number of folds to split data into Grow and Prune for IREP
    * (One fold is used as pruning set.)
    */
  private $numFolds;

  /** Minimal weights of instance weights within a split */
  private $minNo;

  /** Whether check the error rate >= 0.5 in stopping criteria */
  private $checkErr;

  /** Whether use pruning, i.e. the data is clean or not */
  private $usePruning;


  /*** Other useful members  */

  /** class attribute */
  private $classAttr;

  /** # of all the possible conditions in a rule */
  private $numAllConds;

  function __construct($random_seed = 1) { // TODO: in the end use seed = NULL.
    if ($random_seed === NULL) {
      $random_seed = make_seed();
    }

    $this->debug = DEBUGMODE;
    $this->numOptimizations = 2;
    $this->seed = $random_seed;
    $this->numFolds = 3;
    $this->minNo = 2.0;
    $this->checkErr = true;
    $this->usePruning = true;

    $this->classAttr = NULL;
    $this->numAllConds = NULL;
  }

  function initModel() : DiscriminativeModel {
    return new RuleBasedModel();
  }

  /**
   * Builds a model through RIPPER in the order of class frequencies.
   * For each class it's built in two stages: building and optimization
   * 
   * @param model the model to train
   * @param data the training data (wrapped in a structure that holds the appropriate header information for the attributes).
   */
  function teach(DiscriminativeModel &$model, Instances $data) {
    if (DEBUGMODE > 2) echo "PRip->teach(&[model], [data])" . PHP_EOL;

    if (!is_a($model, "RuleBasedModel")) {
      die_error("PRip training requires a DiscriminativeModel of type RuleBasedModel, but got " . get_class($this->trainingMode) . " instead.");
    }

    $data = clone $data;
    srand($this->seed);
    $model->resetRules();

    /* Remove instances with missing class */
    $data->removeUselessInsts();
    if (DEBUGMODE > 1) echo $data->toString() . PHP_EOL;

    /* Initialize ruleset */
    $this->ruleset = [];
    $this->rulesetStats = [];
    $this->classAttr = $data->getClassAttribute();
    $this->numAllConds = RuleStats::numAllConditions($data);

    if ($this->debug) {
      echo "Number of all possible conditions = " . $this->numAllConds . PHP_EOL;
    }
    
    /* Sort by classes frequency */
    $orderedClassCounts = $data->resortClassesByCount();
    if ($this->debug) {
      echo "Sorted classes:\n";
      for ($x = 0; $x < $this->classAttr->numValues(); $x++) {
        echo $x . ": " . $this->classAttr->reprVal($x) . " has "
          . $orderedClassCounts[$x] . " instances." . PHP_EOL;
      }
    }
    
    /* Iterate from less frequent class to the more frequent one */
    for ($classIndex = 0; $classIndex < $data->numClasses() - 1; $classIndex++) {
      
      if ($this->debug) {
        echo "\n\n===========================================================\n"
          . "Class \"" . $this->classAttr->reprVal($classIndex) . "\" [" . $classIndex . "]: "
          . $orderedClassCounts[$classIndex] . " instances\n"
          . "===========================================================\n";
      }

      /* Ignore classes with no members. */
      if ($orderedClassCounts[$classIndex] == 0) {
        if ($this->debug) {
          echo "Ignoring class!\n";
        }
        continue;
      }

      /* The expected FP/err is the proportion of the class */
      $all = array_sum(array_slice($orderedClassCounts, $classIndex));
      $expFPRate = $orderedClassCounts[$classIndex] / $all;

      /* Compute class weights & total weights */
      $totalWeights = $data->getSumOfWeights();
      $classWeights = 0;
      for ($j = 0; $j < $data->numInstances(); $j++) {
        if ($data->inst_classValue($j) == $classIndex) {
          $classWeights += $data->inst_weight($j);
        }
      }

      if ($this->debug) {
        echo "\$all: $all\n";
        echo "\$expFPRate: $expFPRate\n";
        echo "\$classWeights: $classWeights\n";
        echo "\$totalWeights: $totalWeights\n";
      }

      /* DL of default rule, no theory DL, only data DL */
      $defDL = 0.0;
      if ($classWeights > 0) {
        $defDL = RuleStats::dataDL($expFPRate, 0.0, $totalWeights, 0.0,
          $classWeights);
      } else {
        continue; // Subsumed by previous rules
      }

      if (is_nan($defDL) || is_infinite($defDL)) {
        throw new Exception("Should never happen: " . "defDL NaN or infinite!");
      }
      if ($this->debug) {
        echo "The default DL = $defDL" . PHP_EOL;
      }

      $data = $this->rulesetForOneClass($data, $expFPRate, $classIndex, $defDL);
    }

    /* Remove redundant numeric tests from the rules */
    if ($this->debug) {
      echo "Remove redundant numeric tests from the rules" . PHP_EOL;
    }
    foreach ($this->ruleset as $rule) {
      if ($this->debug) {
        echo "rule = " . $rule->toString() . PHP_EOL;
      }
      $rule->cleanUp($data);
      if ($this->debug) {
        echo "rule = " . $rule->toString() . PHP_EOL;
      }
    }
    
    /* Set the default rule */
    if ($this->debug) {
      echo "Set the default rule" . PHP_EOL;
    }
    $defRule = new RipperRule($data->numClasses() - 1);
    $this->ruleset[] = $defRule;
    $this->rulesetStats[] = new RuleStats($data, [$defRule], $this->numAllConds);

    /* Free up memory */
    foreach ($this->rulesetStats as $ruleStat) {
      $ruleStat->cleanUp();
    }
    
    // var_dump($this->ruleset);
    $model->setRules($this->ruleset);
    $model->setAttributes($data->getAttributes());
  }
  
  /**
   * Build a ruleset for a given class
   * 
   * @param data the given data
   * @param expFPRate the expected FP/(FP+FN) used in DL calculation
   * @param classIndex the given class index
   * @param defDL the default DL in the data
   */
  protected function rulesetForOneClass(Instances &$data, float $expFPRate,
    float $classIndex, float $defDL) : Instances {
    if (DEBUGMODE > 2) echo "PRip->rulesetForOneClass(&[data], expFPRate=$expFPRate, classIndex=$classIndex, defDL=$defDL)" . PHP_EOL;

    $newData = $data;
    
    $ruleset = [];
    $stop = false;
    $dl = $minDL = $defDL;

    $rstats = NULL;
    $rst;

    /* Check whether data have positive examples */
    $defHasPositive = true;
    $hasPositive = $defHasPositive;

    /********************** Building stage ***********************/
    if ($this->debug) {
      echo "\n*** Building stage ***\n";
    }

    /* Generate new rules until stopping criteria is met */
    while ((!$stop) && $hasPositive) {
      $oneRule = new RipperRule($classIndex);
      if ($this->usePruning) {
        /* Split data into Grow and Prune */
        $newData = RuleStats::stratify($newData, $this->numFolds);
        // Alternative to stratifying: $newData->randomize();
        list($growData, $pruneData) = RuleStats::partition($newData, $this->numFolds);

        if ($this->debug) {
          echo "\nGrowing rule ...";
        }
        $oneRule->grow($growData, $this->minNo); // Build the rule
        if ($this->debug) {
          echo "One rule found before pruning:"
            . $oneRule->toString($this->classAttr) . PHP_EOL;
        }

        if ($this->debug) {
          echo "\nPruning rule ..." . PHP_EOL;
        }
        $oneRule->prune($pruneData, false); // Prune the rule
        if ($this->debug) {
          echo  PHP_EOL ."One rule found after pruning: "
            . $oneRule->toString($this->classAttr) . PHP_EOL;
        }
      } else {
        if ($this->debug) {
          echo "\nNo pruning: growing a rule ..." . PHP_EOL;
        }
        $oneRule->grow($newData, $this->minNo); // Build the rule
        if ($this->debug) {
          echo "No pruning: one rule found:\n"
            . $oneRule->toString($this->classAttr) . PHP_EOL;
        }
      }

      /* Compute the DL of this ruleset */
      if ($rstats === NULL) {
        $rstats = new RuleStats($newData);
        $rstats->setNumAllConds($this->numAllConds);
      }

      $rstats->pushRule($oneRule);
      // echo $rstats->toString() . PHP_EOL;
      $i_rule = $rstats->getRulesetSize() - 1;

      $dl += $rstats->relativeDL($i_rule, $expFPRate, $this->checkErr);

      if (is_nan($dl) || is_infinite($dl)) {
        throw new Exception("Should never happen: dl in "
          . "building stage NaN or infinite!");
      }
      if ($this->debug) {
        echo "Before optimization(" . $i_rule . "): the dl = " . $dl
          . " | best: " . $minDL . PHP_EOL;
      }

      /* Track the best dl so far */
      if ($dl < $minDL) {
        $minDL = $dl;
      }

      $rst = $rstats->getSimpleStats($i_rule);
      if ($this->debug) {
        echo "The rule covers: " . $rst[0] . " | pos = " . $rst[2]
          . " | neg = " . $rst[4] . "\nThe rule doesn't cover: " . $rst[1]
          . " | pos = " . $rst[5] . PHP_EOL;
      }

      $stop = $this->checkStop($rst, $minDL, $dl);

      if (!$stop) {
        /* Accept rule */
        $ruleset[] = $oneRule;
        /* Update the current data set to be the uncovered one */
        $newData = $rstats->getFiltered($i_rule)[1];
        /* Positives remaining? */
        $hasPositive = $rst[5] > 0.0;
        if ($this->debug) {
          echo "One rule added: has positive? " . $hasPositive . PHP_EOL;
        }
      } else {
        if ($this->debug) {
          echo "Quit rule" . PHP_EOL;
        }
        $rstats->popRule();
      }
    } // while !$stop

    /******************** Optimization stage *******************/
    if ($this->usePruning) {
      for ($z = 0; $z < $this->numOptimizations; $z++) {
        if ($this->debug) {
          echo "\n*** Optimization: run #$z/{$this->numOptimizations} ***" . PHP_EOL;
        }
        $newData = $data;
        
        $stop = false;
        $dl = $minDL = $defDL;
        $i_ruleToOpt = -1;
        
        $finalRulesetStat = new RuleStats($newData);
        $finalRulesetStat->setNumAllConds($this->numAllConds);

        $isResidual = false;
        $hasPositive = $defHasPositive;

        while (!$stop && $hasPositive) {
          $i_ruleToOpt++;

          /* Cover residual positive examples */
          $isResidual = ($i_ruleToOpt >= count($ruleset));

          /* Split data into Grow and Prune */
          $newData = RuleStats::stratify($newData, $this->numFolds);
          // Alternative to stratifying: $newData->randomize();
          list($growData, $pruneData) = RuleStats::partition($newData, $this->numFolds);
          $finalRule = NULL;

          if ($this->debug) {
            echo "\nRule #" . $i_ruleToOpt . "| isResidual?"
              . $isResidual . "| data size: " . $newData->getSumOfWeights() . PHP_EOL;
          }

          if ($isResidual) {
            $newRule = new RipperRule($classIndex);
            if ($this->debug) {
              echo "\nGrowing and pruning" . " a new rule ..." . PHP_EOL;
            }
            $newRule->grow($growData, $this->minNo);
            $newRule->prune($pruneData, false);
            $finalRule = $newRule;
            if ($this->debug) {
              echo "\nNew rule found: "
                . $finalRule->toString($this->classAttr) . PHP_EOL;
            }
          } else {
            $oldRule = $ruleset[$i_ruleToOpt];
            /* Test coverage of the next old rule */
            $covers = $oldRule->coversAll($newData);

            /* Null coverage, no variants can be generated */
            if (!$covers) {
              $finalRulesetStat->pushRule($oldRule);
              continue;
            }

            /* 2 variants */
            if ($this->debug) {
              echo "\nGrowing and pruning" . " Replace ..." . PHP_EOL;
            }
            $replace = new RipperRule($classIndex);
            $replace->grow($growData, $this->minNo);

            // Remove the pruning data covered by the following
            // rules, then simply compute the error rate of the
            // current rule to prune it. According to Ripper,
            // it's equivalent to computing the error of the
            // whole ruleset -- is it true?
            $pruneData = RuleStats::rmCoveredBySuccessives($pruneData, $ruleset,
              $i_ruleToOpt);
            $replace->prune($pruneData, true);

            if ($this->debug) {
              echo "\nGrowing and pruning" . " Revision ..." . PHP_EOL;
            }
            $revision = clone $oldRule;

            /* For revision, first remove the data covered by the old rule */
            $newGrowData = Instances::createEmpty($growData);
            /* Split data */
            for ($b = 0; $b < $growData->numInstances(); $b++) {
              if ($revision->covers($growDatainst, $b)) { // TODO isn't this an error?
                $newGrowData->pushInstance($growData->getInstance($b));
              }
            }
            $revision->grow($newGrowData, $this->minNo);
            $revision->prune($pruneData, true);

            $prevRuleStats = [];
            for ($c = 0; $c < $i_ruleToOpt; $c++) {
              $prevRuleStats[$c] = $finalRulesetStat->getSimpleStats($c);
            }

            /* Now compare the relative DL of the variants */
            $tempRules = array_map("clone", $ruleset);
            $tempRules[$i_ruleToOpt] = $replace;

            $repStat = new RuleStats($data, $tempRules, $this->numAllConds);
            $repStat->countData($i_ruleToOpt, $newData, $prevRuleStats);
            $rst = $repStat->getSimpleStats($i_ruleToOpt);
            if ($this->debug) {
              echo "Replace rule covers: " . $rst[0] . " | pos = "
                . $rst[2] . " | neg = " . $rst[4] . "\nThe rule doesn't cover: "
                . $rst[1] . " | pos = " . $rst[5] . PHP_EOL;
            }

            $repDL = $repStat->relativeDL($i_ruleToOpt, $expFPRate, $this->checkErr);
            if ($this->debug) {
              echo "\nReplace: " . $replace->toString($this->classAttr)
                . " |dl = " . $repDL . PHP_EOL;
            }

            if (is_nan($repDL) || is_infinite($repDL)) {
              throw new Exception("Should never happen: repDL"
                . "in optmz. stage NaN or " . "infinite!");
            }

            $tempRules[$i_ruleToOpt] = $revision;
            $revStat = new RuleStats($data, $tempRules, $this->numAllConds);
            $revStat->countData($i_ruleToOpt, $newData, $prevRuleStats);
            $revDL = $revStat->relativeDL($i_ruleToOpt, $expFPRate, $this->checkErr);

            if ($this->debug) {
              echo "Revision: " . $revision->toString($this->classAttr)
                . " | dl = " . $revDL . PHP_EOL;
            }

            if (is_nan($revDL) || is_infinite($revDL)) {
              throw new Exception("Should never happen: revDL"
                . "in optmz. stage NaN or " . "infinite!");
            }

            $rstats = new RuleStats($data, $ruleset, $this->numAllConds);
            $rstats->countData($i_ruleToOpt, $newData, $prevRuleStats);
            $oldDL = $rstats->relativeDL($i_ruleToOpt, $expFPRate, $this->checkErr);

            if (is_nan($oldDL) || is_infinite($oldDL)) {
              throw new Exception("Should never happen: oldDL"
                . "in optmz. stage NaN or " . "infinite!");
            }
            if ($this->debug) {
              echo "Old rule: " . $oldRule->toString($this->classAttr)
                . " |dl = " . $oldDL . PHP_EOL;
            }

            if ($this->debug) {
              echo "\nrepDL: " . $repDL . "\nrevDL: " . $revDL
                . "\noldDL: " . $oldDL . PHP_EOL;
            }

            if (($oldDL <= $revDL) && ($oldDL <= $repDL)) {
              /* Select old rule */
              $finalRule = $oldRule;
            } else if ($revDL <= $repDL) {
              /* Select revision rule */
              $finalRule = $revision;
            } else {
              /* Select replace rule */
              $finalRule = $replace;
            }
          }

          $finalRulesetStat->pushRule($finalRule);
          $rst = $finalRulesetStat->getSimpleStats($i_ruleToOpt);

          if ($isResidual) {

            $dl += $finalRulesetStat->relativeDL($i_ruleToOpt, $expFPRate,
              $this->checkErr);
            if ($this->debug) {
              echo "After optimization: the dl" . "=" . $dl
                . " | best: " . $minDL . PHP_EOL;
            }

            if ($dl < $minDL) {
              $minDL = $dl; // The best dl so far
            }

            $stop = $this->checkStop($rst, $minDL, $dl);
            if (!$stop) {
              $ruleset[] = $finalRule; // Accept
            } else {
              $finalRulesetStat->popRule(); // Remove last to be re-used
              $i_ruleToOpt--;
            }
          } else {
            $ruleset[$i_ruleToOpt] = $finalRule; // Accept
          }

          if ($this->debug) {
            echo "The rule covers: " . $rst[0] . " | pos = "
              . $rst[2] . " | neg = " . $rst[4] . "\nThe rule doesn't cover: "
              . $rst[1] . " | pos = " . $rst[5] . PHP_EOL;
            echo "\nRuleset so far: [" . PHP_EOL;
            foreach ($ruleset as $x => $rule) {
              echo $x . ": " . $rule->toString($this->classAttr) . PHP_EOL;
            }
            echo "]" . PHP_EOL;
          }

          if ($finalRulesetStat->getRulesetSize() > 0) {
            /* Update the current data set to be the uncovered one */
            $newData = $finalRulesetStat->getFiltered($i_ruleToOpt)[1];
          }
          /* Positives remaining? */
          $hasPositive = $rst[5] > 0.0;
        } // while !$stop && $hasPositive
        $i_ruleToOpt++;
        
        /* Push the rest of the rules */
        if (count($ruleset) > ($i_ruleToOpt + 1)) {
          for ($k = $i_ruleToOpt + 1; $k < count($ruleset); $k++) {
            $finalRulesetStat->pushRule($ruleset[$k]);
          }
        }
        if ($this->debug) {
          echo "\nDeleting rules to decrease DL of the whole ruleset ...";
        }
        $finalRulesetStat->reduceDL($expFPRate, $this->checkErr);
        if ($this->debug) {
          $del = count($ruleset) - $finalRulesetStat->getRulesetSize();
          echo $del . " rules were deleted after DL reduction procedure";
        }
        $ruleset = $finalRulesetStat->getRuleset();
        $rstats = $finalRulesetStat;
       
      } // For each run of optimization
    } // if pruning is used

    /* Concatenate the ruleset for this class to the main ruleset */
    if ($this->debug) {
      echo "\nRuleset: [" . PHP_EOL;
      foreach ($ruleset as $x => $rule) {
        echo $x . " : " . $rule->toString($this->classAttr) . PHP_EOL;
      }
      echo "]" . PHP_EOL;
    }

    $this->ruleset = array_merge($this->ruleset, $ruleset);
    $this->rulesetStats[] = $rstats;

    if ($this->debug) {
      echo "\nCurrent ruleset: [" . PHP_EOL;
      foreach ($this->ruleset as $x => $rule) {
        echo $x . ": " . $rule->toString($this->classAttr) . PHP_EOL;
      }
      echo "]" . PHP_EOL;
      echo "Current rulesetStats: [" . PHP_EOL;
      foreach ($this->rulesetStats as $x => $ruleStat) {
        echo $x . ": " . $ruleStat->toString($this->classAttr) . PHP_EOL;
      }
      echo "]" . PHP_EOL;
    }

    if (count($ruleset) > 0) {
      /* Data not covered */
      return $rstats->getFiltered(count($ruleset) - 1)[1];
    } else {
      return $data;
    }

  }


  /**
   * Check whether the stopping criterion meets
   * 
   * @param rst the statistic of the ruleset
   * @param minDL the min description length so far
   * @param dl the current description length of the ruleset
   * @return true if stop criterion meets, false otherwise
   */
  function checkStop(array $rst, float $minDL, float $dl) : bool {

    if ($dl > $minDL + self::$MAX_DL_SURPLUS) {
      if ($this->debug) {
        echo "DL too large: " . $dl . " | " . $minDL . PHP_EOL;
      }
      return true;
    }
    else if (!($rst[2] > 0.0)) { // Covered positives
      if ($this->debug) {
        echo "Too few positives." . PHP_EOL;
      }
      return true;
    }
    else if (($rst[4] / $rst[0]) >= 0.5) { // Err rate
      if ($this->checkErr) {
        if ($this->debug) {
          echo "Error too large: " . $rst[4] . "/" . $rst[0] . PHP_EOL;
        }
        return true;
      } else {
        return false;
      }
    }
    else { // Don't stop
      if ($this->debug) {
        echo "Continue.";
      }
      return false;
    }
  }
}

?>