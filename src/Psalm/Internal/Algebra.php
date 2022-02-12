<?php

namespace Psalm\Internal;

use Psalm\Exception\ComplicatedExpressionException;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Falsy;
use UnexpectedValueException;

use function array_diff_key;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function mt_rand;

/**
 * @internal
 */
class Algebra
{
    /**
     * @param array<string, non-empty-list<non-empty-list<Assertion>>>  $all_types
     *
     * @return array<string, non-empty-list<non-empty-list<Assertion>>>
     *
     * @psalm-pure
     */
    public static function negateTypes(array $all_types): array
    {
        return array_filter(
            array_map(
                /**
                 * @param  non-empty-list<non-empty-list<Assertion>> $anded_types
                 *
                 * @return list<non-empty-list<Assertion>>
                 */
                static function (array $anded_types): array {
                    if (count($anded_types) > 1) {
                        $new_anded_types = [];

                        foreach ($anded_types as $orred_types) {
                            if (count($orred_types) > 1) {
                                return [];
                            }

                            $new_anded_types[] = $orred_types[0]->getNegation();
                        }

                        return [$new_anded_types];
                    }

                    $new_orred_types = [];

                    foreach ($anded_types[0] as $orred_type) {
                        $new_orred_types[] = [$orred_type->getNegation()];
                    }

                    return $new_orred_types;
                },
                $all_types
            )
        );
    }

    /**
     * This is a very simple simplification heuristic
     * for CNF formulae.
     *
     * It simplifies formulae:
     *     ($a) && ($a || $b) => $a
     *     (!$a) && (!$b) && ($a || $b || $c) => $c
     *
     * @param list<Clause>  $clauses
     *
     * @return list<Clause>
     *
     * @psalm-pure
     */
    public static function simplifyCNF(array $clauses): array
    {
        $clause_count = count($clauses);

        //65536 seems to be a significant threshold, when put at 65537, the code https://psalm.dev/r/216f362ea6 goes
        //from seconds in analysis to many minutes
        if ($clause_count > 65_536) {
            return [];
        }

        if ($clause_count > 50) {
            $all_has_unknown = true;

            foreach ($clauses as $clause) {
                $clause_has_unknown = false;
                foreach ($clause->possibilities as $key => $_) {
                    if ($key[0] === '*') {
                        $clause_has_unknown = true;
                        break;
                    }
                }

                if (!$clause_has_unknown) {
                    $all_has_unknown = false;
                    break;
                }
            }

            if ($all_has_unknown) {
                return $clauses;
            }
        }

        $cloned_clauses = [];

        // avoid strict duplicates
        foreach ($clauses as $clause) {
            $unique_clause = $clause->makeUnique();
            $cloned_clauses[$unique_clause->hash] = $unique_clause;
        }

        // remove impossible types
        foreach ($cloned_clauses as $clause_a_hash => $clause_a) {
            if (!$clause_a->reconcilable || $clause_a->wedge) {
                continue;
            }

            if (count($clause_a->possibilities) !== 1 || count(array_values($clause_a->possibilities)[0]) !== 1) {
                foreach ($cloned_clauses as $clause_b) {
                    if ($clause_a === $clause_b || !$clause_b->reconcilable || $clause_b->wedge) {
                        continue;
                    }

                    if (array_keys($clause_a->possibilities) === array_keys($clause_b->possibilities)) {
                        $opposing_keys = [];

                        foreach ($clause_a->possibilities as $key => $a_possibilities) {
                            $b_possibilities = $clause_b->possibilities[$key];

                            if ($clause_a->possibility_strings[$key] === $clause_b->possibility_strings[$key]) {
                                continue;
                            }

                            if (count($a_possibilities) === 1 && count($b_possibilities) === 1) {
                                if ($a_possibilities[0]->isNegationOf($b_possibilities[0])) {
                                    $opposing_keys[] = $key;
                                    continue;
                                }
                            }

                            continue 2;
                        }

                        if (count($opposing_keys) === 1) {
                            unset($cloned_clauses[$clause_a_hash]);

                            $clause_a = $clause_a->removePossibilities($opposing_keys[0]);

                            if (!$clause_a) {
                                continue 2;
                            }

                            $cloned_clauses[$clause_a->hash] = $clause_a;
                        }
                    }
                }

                continue;
            }

            $clause_var = array_keys($clause_a->possibilities)[0];
            $only_type = array_pop(array_values($clause_a->possibilities)[0]);
            $negated_clause_type = $only_type->getNegation();
            $negated_clause_type_string = (string)$negated_clause_type;

            foreach ($cloned_clauses as $clause_b_hash => $clause_b) {
                if ($clause_a === $clause_b || !$clause_b->reconcilable || $clause_b->wedge) {
                    continue;
                }

                if (isset($clause_b->possibilities[$clause_var])) {
                    $unmatched = [];
                    $matched = [];

                    foreach ($clause_b->possibilities[$clause_var] as $possible_type) {
                        if ((string)$possible_type === $negated_clause_type_string) {
                            $matched[] = $possible_type;
                        } else {
                            $unmatched[] = $possible_type;
                        }
                    }

                    if ($matched) {
                        $clause_var_possibilities = $unmatched;

                        unset($cloned_clauses[$clause_b_hash]);

                        if (!$clause_var_possibilities) {
                            $updated_clause = $clause_b->removePossibilities($clause_var);

                            if ($updated_clause) {
                                $cloned_clauses[$updated_clause->hash] = $updated_clause;
                            }
                        } else {
                            $updated_clause = $clause_b->addPossibilities(
                                $clause_var,
                                $clause_var_possibilities
                            );

                            $cloned_clauses[$updated_clause->hash] = $updated_clause;
                        }
                    }
                }
            }
        }

        $simplified_clauses = [];

        foreach ($cloned_clauses as $clause_a) {
            $is_redundant = false;

            foreach ($cloned_clauses as $clause_b) {
                if ($clause_a === $clause_b
                    || !$clause_b->reconcilable
                    || $clause_b->wedge
                    || $clause_a->wedge
                ) {
                    continue;
                }

                if ($clause_a->contains($clause_b)) {
                    $is_redundant = true;
                    break;
                }
            }

            if (!$is_redundant) {
                $simplified_clauses[$clause_a->hash] = $clause_a;
            }
        }

        $clause_count = count($simplified_clauses);

        // simplify (A || X) && (!A || Y) && (X || Y)
        // to
        // simplify (A || X) && (!A || Y)
        // where X and Y are sets of orred terms
        if ($clause_count > 2 && $clause_count < 256) {
            $clauses = array_values($simplified_clauses);
            for ($i = 0; $i < $clause_count; $i++) {
                $clause_a = $clauses[$i];
                for ($k = $i + 1; $k < $clause_count; $k++) {
                    $clause_b = $clauses[$k];
                    $common_keys = array_keys(
                        array_intersect_key($clause_a->possibilities, $clause_b->possibilities)
                    );
                    if ($common_keys) {
                        $common_negated_keys = [];
                        foreach ($common_keys as $common_key) {
                            if (count($clause_a->possibilities[$common_key]) === 1
                                && count($clause_b->possibilities[$common_key]) === 1
                                && $clause_a->possibilities[$common_key][0]->isNegationOf(
                                    $clause_b->possibilities[$common_key][0]
                                )
                            ) {
                                $common_negated_keys[] = $common_key;
                            }
                        }

                        if ($common_negated_keys) {
                            $new_possibilities = [];

                            foreach ($clause_a->possibilities as $var_id => $possibilities) {
                                if (in_array($var_id, $common_negated_keys, true)) {
                                    continue;
                                }

                                if (!isset($new_possibilities[$var_id])) {
                                    $new_possibilities[$var_id] = $possibilities;
                                } else {
                                    $new_possibilities[$var_id] = array_merge(
                                        $new_possibilities[$var_id],
                                        $possibilities,
                                    );
                                }
                            }

                            foreach ($clause_b->possibilities as $var_id => $possibilities) {
                                if (in_array($var_id, $common_negated_keys, true)) {
                                    continue;
                                }

                                if (!isset($new_possibilities[$var_id])) {
                                    $new_possibilities[$var_id] = $possibilities;
                                } else {
                                    $new_possibilities[$var_id] = array_merge(
                                        $new_possibilities[$var_id],
                                        $possibilities,
                                    );
                                }
                            }

                            /** @psalm-suppress MixedArgumentTypeCoercion due I think to Psalm bug */
                            $conflict_clause = (new Clause(
                                $new_possibilities,
                                $clause_a->creating_conditional_id,
                                $clause_a->creating_conditional_id,
                                false,
                                true,
                                true,
                                []
                            ))->makeUnique();

                            unset($simplified_clauses[$conflict_clause->hash]);
                        }
                    }
                }
            }
        }

        return array_values($simplified_clauses);
    }

    /**
     * Look for clauses with only one possible value
     *
     * @param  list<Clause>  $clauses
     * @param  array<string, bool> $cond_referenced_var_ids
     * @param  array<string, array<int, array<int, Assertion>>> $active_truths
     *
     * @return array<string, list<list<Assertion>>>
     */
    public static function getTruthsFromFormula(
        array $clauses,
        ?int $creating_conditional_id = null,
        array &$cond_referenced_var_ids = [],
        array &$active_truths = []
    ): array {
        $truths = [];
        $active_truths = [];

        if ($clauses === []) {
            return [];
        }

        foreach ($clauses as $clause) {
            if (!$clause->reconcilable || count($clause->possibilities) !== 1) {
                continue;
            }

            foreach ($clause->possibilities as $var => $possible_types) {
                if ($var[0] === '*') {
                    continue;
                }

                // if there's only one possible type, return it
                if (count($possible_types) === 1) {
                    $possible_type = array_pop($possible_types);

                    if (isset($truths[$var]) && !isset($clause->redefined_vars[$var])) {
                        $truths[$var][] = [$possible_type];
                    } else {
                        $truths[$var] = [[$possible_type]];
                    }

                    if ($creating_conditional_id && $creating_conditional_id === $clause->creating_conditional_id) {
                        if (!isset($active_truths[$var])) {
                            $active_truths[$var] = [];
                        }

                        $active_truths[$var][count($truths[$var]) - 1] = [$possible_type];
                    }
                } else {
                    // if there's only one active clause, return all the non-negation clause members ORed together
                    $things_that_can_be_said = [];

                    foreach ($possible_types as $assertion) {
                        if ($assertion instanceof Falsy || !$assertion->isNegation()) {
                            $things_that_can_be_said[(string)$assertion] = $assertion;
                        }
                    }

                    if ($things_that_can_be_said && count($things_that_can_be_said) === count($possible_types)) {
                        if ($clause->generated && count($possible_types) > 1) {
                            unset($cond_referenced_var_ids[$var]);
                        }

                        $truths[$var] = [array_values($things_that_can_be_said)];

                        if ($creating_conditional_id && $creating_conditional_id === $clause->creating_conditional_id) {
                            $active_truths[$var] = [array_values($things_that_can_be_said)];
                        }
                    }
                }
            }
        }

        return $truths;
    }

    /**
     * @param non-empty-list<Clause>  $clauses
     *
     * @return list<Clause>
     *
     * @psalm-pure
     */
    public static function groupImpossibilities(array $clauses): array
    {
        $complexity = 1;

        $seed_clauses = [];

        $clause = array_pop($clauses);

        if (!$clause->wedge) {
            if ($clause->impossibilities === null) {
                throw new UnexpectedValueException('$clause->impossibilities should not be null');
            }

            foreach ($clause->impossibilities as $var => $impossible_types) {
                foreach ($impossible_types as $impossible_type) {
                    $seed_clause = new Clause(
                        [$var => [$impossible_type]],
                        $clause->creating_conditional_id,
                        $clause->creating_object_id
                    );

                    $seed_clauses[] = $seed_clause;

                    ++$complexity;
                }
            }
        }

        if (!$clauses || !$seed_clauses) {
            return $seed_clauses;
        }

        while ($clauses) {
            $clause = array_pop($clauses);

            $new_clauses = [];

            foreach ($seed_clauses as $grouped_clause) {
                if ($clause->impossibilities === null) {
                    throw new UnexpectedValueException('$clause->impossibilities should not be null');
                }

                foreach ($clause->impossibilities as $var => $impossible_types) {
                    foreach ($impossible_types as $impossible_type) {
                        $new_clause_possibilities = $grouped_clause->possibilities;

                        if (isset($grouped_clause->possibilities[$var])) {
                            $new_clause_possibilities[$var] = array_values(
                                array_unique(
                                    array_merge([$impossible_type], $new_clause_possibilities[$var])
                                )
                            );

                            $removed_indexes = [];

                            for ($i = 0, $l = count($new_clause_possibilities[$var]); $i < $l; $i++) {
                                for ($j = $i + 1; $j < $l; $j++) {
                                    $ith = $new_clause_possibilities[$var][$i];
                                    $jth = $new_clause_possibilities[$var][$j];

                                    if ($ith->isNegationOf($jth)) {
                                        $removed_indexes[$i] = true;
                                        $removed_indexes[$j] = true;
                                    }
                                }
                            }

                            if ($removed_indexes) {
                                $new_possibilities = array_values(
                                    array_diff_key(
                                        $new_clause_possibilities[$var],
                                        $removed_indexes
                                    )
                                );

                                if (!$new_possibilities) {
                                    unset($new_clause_possibilities[$var]);
                                } else {
                                    $new_clause_possibilities[$var] = $new_possibilities;
                                }
                            }
                        } else {
                            $new_clause_possibilities[$var] = [$impossible_type];
                        }

                        if (!$new_clause_possibilities) {
                            continue;
                        }

                        $new_clause = new Clause(
                            $new_clause_possibilities,
                            $grouped_clause->creating_conditional_id,
                            $clause->creating_object_id,
                            false,
                            true,
                            true,
                            []
                        );

                        $new_clauses[] = $new_clause;

                        ++$complexity;

                        if ($complexity > 20_000) {
                            throw new ComplicatedExpressionException();
                        }
                    }
                }
            }

            $seed_clauses = $new_clauses;
        }

        return $seed_clauses;
    }

    /**
     * @param list<Clause>  $left_clauses
     * @param list<Clause>  $right_clauses
     *
     * @return list<Clause>
     *
     * @psalm-pure
     */
    public static function combineOredClauses(
        array $left_clauses,
        array $right_clauses,
        int $conditional_object_id
    ): array {
        if (count($left_clauses) > 60_000 || count($right_clauses) > 60_000) {
            return [];
        }

        $clauses = [];

        $all_wedges = true;
        $has_wedge = false;

        foreach ($left_clauses as $left_clause) {
            foreach ($right_clauses as $right_clause) {
                $all_wedges = $all_wedges && ($left_clause->wedge && $right_clause->wedge);
                $has_wedge = $has_wedge || ($left_clause->wedge && $right_clause->wedge);
            }
        }

        if ($all_wedges) {
            return [new Clause([], $conditional_object_id, $conditional_object_id, true)];
        }

        foreach ($left_clauses as $left_clause) {
            foreach ($right_clauses as $right_clause) {
                if ($left_clause->wedge && $right_clause->wedge) {
                    // handled below
                    continue;
                }

                /** @var  array<string, non-empty-list<Assertion>> */
                $possibilities = [];

                $can_reconcile = true;

                if ($left_clause->wedge ||
                    $right_clause->wedge ||
                    !$left_clause->reconcilable ||
                    !$right_clause->reconcilable
                ) {
                    $can_reconcile = false;
                }

                foreach ($left_clause->possibilities as $var => $possible_types) {
                    if (isset($right_clause->redefined_vars[$var])) {
                        continue;
                    }

                    if (isset($possibilities[$var])) {
                        $possibilities[$var] = array_merge($possibilities[$var], $possible_types);
                    } else {
                        $possibilities[$var] = $possible_types;
                    }
                }

                foreach ($right_clause->possibilities as $var => $possible_types) {
                    if (isset($possibilities[$var])) {
                        $possibilities[$var] = array_merge($possibilities[$var], $possible_types);
                    } else {
                        $possibilities[$var] = $possible_types;
                    }
                }

                if (count($left_clauses) > 1 || count($right_clauses) > 1) {
                    foreach ($possibilities as $var => $p) {
                        $possibilities[$var] = array_values(array_unique($p));
                    }
                }

                foreach ($possibilities as $var_possibilities) {
                    if (count($var_possibilities) === 2) {
                        if ($var_possibilities[0]->isNegationOf($var_possibilities[1])) {
                            continue 2;
                        }
                    }
                }

                $creating_conditional_id =
                    $right_clause->creating_conditional_id === $left_clause->creating_conditional_id
                    ? $right_clause->creating_conditional_id
                    : $conditional_object_id;

                $clauses[] = new Clause(
                    $possibilities,
                    $creating_conditional_id,
                    $creating_conditional_id,
                    false,
                    $can_reconcile,
                    $right_clause->generated
                        || $left_clause->generated
                        || count($left_clauses) > 1
                        || count($right_clauses) > 1,
                    []
                );
            }
        }

        if ($has_wedge) {
            $clauses[] = new Clause([], $conditional_object_id, $conditional_object_id, true);
        }

        return $clauses;
    }

    /**
     * Negates a set of clauses
     * negateClauses([$a || $b]) => !$a && !$b
     * negateClauses([$a, $b]) => !$a || !$b
     * negateClauses([$a, $b || $c]) =>
     *   (!$a || !$b) &&
     *   (!$a || !$c)
     * negateClauses([$a, $b || $c, $d || $e || $f]) =>
     *   (!$a || !$b || !$d) &&
     *   (!$a || !$b || !$e) &&
     *   (!$a || !$b || !$f) &&
     *   (!$a || !$c || !$d) &&
     *   (!$a || !$c || !$e) &&
     *   (!$a || !$c || !$f)
     *
     * @param list<Clause>  $clauses
     *
     * @return non-empty-list<Clause>
     */
    public static function negateFormula(array $clauses): array
    {
        $clauses = array_filter(
            $clauses,
            static fn($clause) => $clause->reconcilable
        );

        if (!$clauses) {
            $cond_id = mt_rand(0, 100_000_000);
            return [new Clause([], $cond_id, $cond_id, true)];
        }

        $clauses_with_impossibilities = [];

        foreach ($clauses as $clause) {
            $clauses_with_impossibilities[] = $clause->calculateNegation();
        }

        unset($clauses);

        $impossible_clauses = self::groupImpossibilities($clauses_with_impossibilities);

        if (!$impossible_clauses) {
            $cond_id = mt_rand(0, 100_000_000);
            return [new Clause([], $cond_id, $cond_id, true)];
        }

        $negated = self::simplifyCNF($impossible_clauses);

        if (!$negated) {
            $cond_id = mt_rand(0, 100_000_000);
            return [new Clause([], $cond_id, $cond_id, true)];
        }

        return $negated;
    }
}
