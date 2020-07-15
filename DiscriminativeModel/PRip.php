<?php

/*
 * Interface for learner/optimizers
 */
interface Learner {
    function teach(_DiscriminativeModel &$model, Instances $data);
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

 * // (nota: l'allenamento dice anche quanto il modello e' buono. Nel caso di RuleBasedModel() ci sono dei metodi per valutare ogni singola regola. Come si valuta? vedremo)
 */
class PRip implements Learner {

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

    $this->debug = true;
    $this->numOptimizations = 2;
    $this->seed = $random_seed;
    $this->numFolds = 3;
    $this->minNo = 2.0;
    $this->checkErr = true;
    $this->usePruning = true;

    $this->classAttr = NULL;
    $this->numAllConds = NULL;
  }

  /**
   * Builds a model through RIPPER in the order of class frequencies.
   * For each class it's built in two stages: building and optimization
   * 
   * @param instances the training data
   * @throws Exception if classifier can't be built successfully
   */
  function teach(&$model, $data) {
    echo "PRip->teach(&[model], [data])" . PHP_EOL;

    /* Remove instances with missing class */
    $data->removeUselessInsts();
    echo $data->toString() . PHP_EOL;

    srand($this->seed);

    $model->resetRules();
    $this->ruleset = [];
    $this->rulesetStats = [];
    $this->numAllConds = RuleStats::numAllConditions($data);

    if ($this->debug) {
      echo "Number of all possible conditions = " . $this->numAllConds . PHP_EOL;
    }
    
    // Sort by class FREQ_ASCEND
    // m_Distributions = new ArrayList<double[]>();

    // Sort by classes frequency
    $orderedClassCounts = $data->sortClassesByCount();
    $this->classAttr = $data->getClassAttribute();
    if ($this->debug) {
      echo "Sorted classes:\n";
      for ($x = 0; $x < $this->classAttr->numValues(); $x++) {
        echo $x . ": " . $this->classAttr->reprVal($x) . " has "
          . $orderedClassCounts[$x] . " instances." . PHP_EOL;
      }
    }
    
    // Iterate from less prevalent class to more frequent one
    for ($y = 0; $y < $data->numClasses() - 1; $y++) { // For each
                                                                // class

      $classIndex = $y;
      if ($this->debug) {
        echo "\n\n=====================================\n"
          . "Class " . $this->classAttr->reprVal($classIndex) . "(" . $classIndex . "): "
          . $orderedClassCounts[$y] . " instances\n"
          . "=====================================\n";
      }

      // Ignore classes with no members.
      if ($orderedClassCounts[$y] == 0) {
        if ($this->debug) {
          echo "Ignoring class!\n";
        }
      continue;
      }

      // The expected FP/err is the proportion of the class
      $all = array_sum(array_slice($orderedClassCounts, $y));
      $expFPRate = $orderedClassCounts[$y] / $all;

      $classYWeights = 0; $totalWeights = 0;
      for ($j = 0; $j < $data->numInstances(); $j++) {
        $totalWeights += $data->inst_weight($j);
        if ($data->inst_classValue($j) == $y) {
          $classYWeights += $data->inst_weight($j);
        }
      }

      if ($this->debug) {
          echo "\$all: $all!\n";
          echo "\$expFPRate: $expFPRate!\n";
          echo "\$classYWeights: $classYWeights!\n";
          echo "\$totalWeights: $totalWeights!\n";
      }

      // DL of default rule, no theory DL, only data DL
      $defDL = 0.0;
      if ($classYWeights > 0) {
        $defDL = RuleStats::dataDL($expFPRate, 0.0, $totalWeights, 0.0,
          $classYWeights);
      } else {
        continue; // Subsumed by previous rules
      }

      if (is_nan($defDL) || is_infinite($defDL)) {
        throw new Exception("Should never happen: " . "defDL NaN or infinite!");
      }
      if ($this->debug) {
        echo "The default DL = $defDL\n";
      }

      $data = $this->rulesetForOneClass($data, $expFPRate, $classIndex, $defDL);
    }

    // Remove redundant numeric tests from the rules
    if ($this->debug) {
      echo "Remove redundant numeric tests from the rules\n";
    }
    foreach ($this->ruleset as &$rule) {
      if ($this->debug) {
        echo "rule = " . $rule->toString();
      }
      $rule->cleanUp($data);
      if ($this->debug) {
        echo "rule = " . $rule->toString();
      }
    }
    
    // Set the default rule
    if ($this->debug) {
      echo "Set the default rule\n";
    }
    $defRule = new RipperRule();
    $defRule->setConsequent($data->numClasses() - 1);
    $this->ruleset[] = $defRule;

    $defRuleStat = new RuleStats();
    $defRuleStat->setData($data);
    $defRuleStat->setNumAllConds($this->numAllConds);
    $defRuleStat->pushRule($defRule);
    $this->rulesetStats[] = $defRuleStat;

    foreach ($this->rulesetStats as $ruleStat) {
      for ($i_r = 0; $i_r < $ruleStat->getRulesetSize(); $i_r++) {
        $classDist = $ruleStat->getDistributions($i_r);
        normalize($classDist);
        // if ($classDist !== NULL) {
        //   m_Distributions.add(((ClassOrder) m_Filter)
        //     .distributionsByOriginalIndex($classDist));
        // }
      }
    }

    // free up memory
    foreach ($this->rulesetStats as &$ruleStat) {
      $ruleStat->cleanUp();
    }
    
    /**/
    $model->setRules($this->ruleset);
  }
  
  /**
   * Build a ruleset for the given class according to the given data
   * 
   * @param expFPRate the expected FP/(FP+FN) used in DL calculation
   * @param data the given data
   * @param classIndex the given class index
   * @param defDL the default DL in the data
   */
  protected function rulesetForOneClass(Instances &$data, float $expFPRate,
    float $classIndex, float $defDL) {
    echo "PRip->rulesetForOneClass(&[model], &[data], expFPRate=$expFPRate, classIndex=$classIndex, defDL=$defDL)" . PHP_EOL;

    $newData = $data;
    $growData;
    $pruneData;
    
    $stop = false;
    $ruleset = [];

    $dl = $defDL;
    $minDL = $defDL;
    $rstats = null;
    $rst;

    // Check whether data have positive examples
    $defHasPositive = true; // No longer used
    $hasPositive = $defHasPositive;

    /********************** Building stage ***********************/
    if ($this->debug) {
      echo "\n*** Building stage ***\n";
    }

    // Generate new rules until stopping criteria is met
    while ((!$stop) && $hasPositive) {
      $oneRule = new RipperRule();
      $oneRule->setConsequent($classIndex); // Must set first
      if ($this->usePruning) {
        /* Split data into Grow and Prune */

        // We should have stratified the data, but ripper seems
        // to have a bug that makes it not to do so. In order
        // to simulate it more precisely, we do the same thing.
        // newData.randomize(m_Random);
        $newData = RuleStats::stratify($newData, $this->numFolds);
        list($growData, $pruneData) = RuleStats::partition($newData, $this->numFolds);
        // growData=newData.trainCV($this->numFolds, $this->numFolds-1);
        // pruneData=newData.testCV($this->numFolds, $this->numFolds-1);

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

      // Compute the DL of this ruleset
      if ($rstats === null) { // First rule
        $rstats = new RuleStats();
        $rstats->setNumAllConds($this->numAllConds);
        $rstats->setData($newData);
      }

      $rstats->pushRule($oneRule);
      echo $rstats->toString() . PHP_EOL;
      $last = $rstats->getRulesetSize() - 1; // Index of last rule

      $dl += $rstats->relativeDL($last, $expFPRate, $this->checkErr);

      if (is_nan($dl) || is_infinite($dl)) {
        throw new Exception("Should never happen: dl in "
          . "building stage NaN or infinite!");
      }
      if ($this->debug) {
        echo "Before optimization(" . $last . "): the dl = " . $dl
          . " | best: " . $minDL . PHP_EOL;
      }

      if ($dl < $minDL) {
        $minDL = $dl; // The best dl so far
      }

      $rst = $rstats->getSimpleStats($last);
      if ($this->debug) {
        echo "The rule covers: " . $rst[0] . " | pos = " . $rst[2]
          . " | neg = " . $rst[4] . "\nThe rule doesn't cover: " . $rst[1]
          . " | pos = " . $rst[5] . PHP_EOL;
      }

      $stop = $this->checkStop($rst, $minDL, $dl);

      if (!$stop) {
        $ruleset[] = $oneRule; // Accepted
        $newData = $rstats->getFiltered($last)[1];// Data not covered
        $hasPositive = $rst[5] > 0.0; // Positives remaining?
        if ($this->debug) {
          echo "One rule added: has positive? " . $hasPositive . PHP_EOL;
        }
      } else {
        if ($this->debug) {
          echo "Quit rule" . PHP_EOL;
        }
        $rstats->popRule(); // Remove last to be re-used
      }
    }// while !stop

    /******************** Optimization stage *******************/
    if ($this->usePruning) {
      $finalRulesetStat = NULL;
      for ($z = 0; $z < $this->numOptimizations; $z++) {
        if ($this->debug) {
          echo "\n*** Optimization: run #" . $z . " ***" . PHP_EOL;
        }
        echo "TODO" . PHP_EOL;
        /*
        $newData = $data;
        $finalRulesetStat = new RuleStats();
        $finalRulesetStat.setData($newData);
        $finalRulesetStat.setNumAllConds($this->numAllConds);
        int position = 0;
        stop = false;
        boolean isResidual = false;
        hasPositive = defHasPositive;
        dl = minDL = defDL;

        oneRule: while (!stop && hasPositive) {

          isResidual = (position >= ruleset.size()); // Cover residual positive
                                                     // examples
          // Re-do shuffling and stratification
          // newData.randomize(m_Random);
          $newData = RuleStats::stratify($newData, $this->numFolds);
          list($growData, $pruneData) = RuleStats::partition($newData, $this->numFolds);
          // growData=newData.trainCV($this->numFolds, $this->numFolds-1);
          // pruneData=newData.testCV($this->numFolds, $this->numFolds-1);
          RipperRule finalRule;

          if ($this->debug) {
            echo "\nRule #" + position + "| isResidual?"
              + isResidual + "| data size: " + newData.sumOfWeights());
          }

          if (isResidual) {
            RipperRule newRule = new RipperRule();
            newRule.setConsequent(classIndex);
            if ($this->debug) {
              echo "\nGrowing and pruning" + " a new rule ...";
            }
            newRule.grow(growData, $this->minNo);
            newRule.prune(pruneData, false);
            finalRule = newRule;
            if ($this->debug) {
              echo "\nNew rule found: "
                + newRule.toString($this->classAttr);
            }
          } else {
            RipperRule oldRule = (RipperRule) ruleset.get(position);
            boolean covers = false;
            // Test coverage of the next old rule
            for (int i = 0; i < newData.numInstances(); i++) {
              if (oldRule.covers(newData.getInstance(i))) {
                covers = true;
                break;
              }
            }

            if (!covers) {// Null coverage, no variants can be generated
              finalRulesetStat.pushRule(oldRule);
              position++;
              continue oneRule;
            }

            // 2 variants
            if ($this->debug) {
              echo "\nGrowing and pruning" + " Replace ...";
            }
            RipperRule replace = new RipperRule();
            replace.setConsequent(classIndex);
            replace.grow(growData, $this->minNo);

            // Remove the pruning data covered by the following
            // rules, then simply compute the error rate of the
            // current rule to prune it. According to Ripper,
            // it's equivalent to computing the error of the
            // whole ruleset -- is it true?
            pruneData = RuleStats.rmCoveredBySuccessives(pruneData, ruleset,
              position);
            replace.prune(pruneData, true);

            if ($this->debug) {
              echo "\nGrowing and pruning" + " Revision ...";
            }
            RipperRule revision = (RipperRule) oldRule.copy();

            // For revision, first rm the data covered by the old rule
            Instances newGrowData = new Instances(growData, 0);
            for (int b = 0; b < growData.numInstances(); b++) {
              Instance inst = growData.getInstance(b);
              if (revision.covers(inst)) {
                newGrowData.add(inst);
              }
            }
            revision.grow(newGrowData, $this->minNo);
            revision.prune(pruneData, true);

            double[][] prevRuleStats = new double[position][6];
            for (int c = 0; c < position; c++) {
              prevRuleStats[c] = finalRulesetStat.getSimpleStats(c);
            }

            // Now compare the relative DL of variants
            ArrayList<Rule> tempRules = new ArrayList<Rule>(ruleset.size());
            for (Rule r : ruleset) {
              tempRules.add((Rule) r.copy());
            }
            tempRules.set(position, replace);

            RuleStats repStat = new RuleStats(data, tempRules);
            repStat.setNumAllConds($this->numAllConds);
            repStat.countData(position, newData, prevRuleStats);
            // repStat.countData();
            rst = repStat.getSimpleStats(position);
            if ($this->debug) {
              echo "Replace rule covers: " + rst[0] + " | pos = "
                + rst[2] + " | neg = " + rst[4] + "\nThe rule doesn't cover: "
                + rst[1] + " | pos = " + rst[5]);
            }

            double repDL = repStat.relativeDL(position, expFPRate, $this->checkErr);
            if ($this->debug) {
              echo "\nReplace: " + replace.toString($this->classAttr)
                + " |dl = " + repDL);
            }

            if (Double.isNaN(repDL) || Double.isInfinite(repDL)) {
              throw new Exception("Should never happen: repDL"
                + "in optmz. stage NaN or " + "infinite!");
            }

            tempRules.set(position, revision);
            RuleStats revStat = new RuleStats(data, tempRules);
            revStat.setNumAllConds($this->numAllConds);
            revStat.countData(position, newData, prevRuleStats);
            // revStat.countData();
            double revDL = revStat.relativeDL(position, expFPRate, $this->checkErr);

            if ($this->debug) {
              echo "Revision: " + revision.toString($this->classAttr)
                + " |dl = " + revDL);
            }

            if (Double.isNaN(revDL) || Double.isInfinite(revDL)) {
              throw new Exception("Should never happen: revDL"
                + "in optmz. stage NaN or " + "infinite!");
            }

            rstats = new RuleStats(data, ruleset);
            rstats.setNumAllConds($this->numAllConds);
            rstats.countData(position, newData, prevRuleStats);
            // rstats.countData();
            double oldDL = rstats.relativeDL(position, expFPRate, $this->checkErr);

            if (Double.isNaN(oldDL) || Double.isInfinite(oldDL)) {
              throw new Exception("Should never happen: oldDL"
                + "in optmz. stage NaN or " + "infinite!");
            }
            if ($this->debug) {
              echo "Old rule: " + oldRule.toString($this->classAttr)
                + " |dl = " + oldDL);
            }

            if ($this->debug) {
              echo "\nrepDL: " + repDL + "\nrevDL: " + revDL
                + "\noldDL: " + oldDL);
            }

            if ((oldDL <= revDL) && (oldDL <= repDL)) {
              finalRule = oldRule; // Old the best
            } else if (revDL <= repDL) {
              finalRule = revision; // Revision the best
            } else {
              finalRule = replace; // Replace the best
            }
          }

          finalRulesetStat.pushRule(finalRule);
          rst = finalRulesetStat.getSimpleStats(position);

          if (isResidual) {

            dl += finalRulesetStat.relativeDL(position, expFPRate, $this->checkErr);
            if ($this->debug) {
              echo "After optimization: the dl" + "=" + dl
                + " | best: " + minDL);
            }

            if (dl < minDL) {
              minDL = dl; // The best dl so far
            }

            stop = checkStop(rst, minDL, dl);
            if (!stop) {
              ruleset.add(finalRule); // Accepted
            } else {
              finalRulesetStat.removeLast(); // Remove last to be re-used
              position--;
            }
          } else {
            ruleset.set(position, finalRule); // Accepted
          }

          if ($this->debug) {
            echo "The rule covers: " + rst[0] + " | pos = "
              + rst[2] + " | neg = " + rst[4] + "\nThe rule doesn't cover: "
              + rst[1] + " | pos = " + rst[5]);
            echo "\nRuleset so far: ";
            for (int x = 0; x < ruleset.size(); x++) {
              echo x + ": "
                + ((RipperRule) ruleset.get(x)).toString($this->classAttr);
            }
            echo );
          }

          // Data not covered
          if (finalRulesetStat.getRulesetSize() > 0) {
            newData = finalRulesetStat.getFiltered(position)[1];
          }
          hasPositive = greater(rst[5], 0.0); // Positives remaining?
          position++;
        } // while !stop && hasPositive

        if (ruleset.size() > (position + 1)) { // Hasn't gone through yet
          for (int k = position + 1; k < ruleset.size(); k++) {
            finalRulesetStat.pushRule(ruleset.get(k));
          }
        }
        if ($this->debug) {
          echo "\nDeleting rules to decrease"
            + " DL of the whole ruleset ...");
        }
        finalRulesetStat.reduceDL(expFPRate, $this->checkErr);
        if ($this->debug) {
          int del = ruleset.size() - finalRulesetStat.getRulesetSize();
          echo del + " rules are deleted"
            + " after DL reduction procedure");
        }
        ruleset = finalRulesetStat.getRuleset();
        rstats = finalRulesetStat;
        */
       
      } // For each run of optimization
    } // if pruning is used

    // Concatenate the ruleset for this class to the whole ruleset
    if ($this->debug) {
      echo "\nFinal ruleset: [";
      foreach ($ruleset as $x => $rule) {
        echo $x . " : " . $rule->toString($this->classAttr);
      }
      echo "]" . PHP_EOL;
    }

    $this->ruleset = array_merge($this->ruleset, $ruleset);
    $this->rulesetStats[] = $rstats;

    if ($this->debug) {
      echo "\nCurrent ruleset: [";
      foreach ($this->ruleset as $x => $rule) {
        echo $x . ": " . $rule->toString($this->classAttr);
      }
      echo "]" . PHP_EOL;
      echo "Current rulesetStats: [";
      foreach ($this->rulesetStats as $x => $ruleStat) {
        echo $x . ": " . $ruleStat->toString($this->classAttr);
      }
      echo "]" . PHP_EOL;
    }

    if (count($ruleset) > 0) {
      // Data not covered
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
  function checkStop($rst, $minDL, $dl) {

    if ($dl > $minDL + self::$MAX_DL_SURPLUS) {
      if ($this->debug) {
        echo "DL too large: " . $dl . " | " . $minDL . PHP_EOL;
      }
      return true;
    }
    else if (!($rst[2] > 0.0)) {// Covered positives
      if ($this->debug) {
        echo "Too few positives." . PHP_EOL;
      }
      return true;
    }
    else if (($rst[4] / $rst[0]) >= 0.5) {// Err rate
      if ($this->checkErr) {
        if ($this->debug) {
          echo "Error too large: " . $rst[4] . "/" . $rst[0] . PHP_EOL;
        }
        return true;
      } else {
        return false;
      }
    }
    else {// Not stops
      if ($this->debug) {
        echo "Continue.";
      }
      return false;
    }
  }
}

?>