<?php

namespace App\Support;

use InvalidArgumentException;

class PracticeProblemGenerator
{
    public function catalogForGrade(int $grade): array
    {
        return array_map($this->publicMetadata(...), PracticeCurriculum::forGrade($grade));
    }

    public function competency(int $grade, string $key): array
    {
        $item = PracticeCurriculum::find($grade, $key);
        if (!$item) {
            throw new InvalidArgumentException('Unknown practice competency.');
        }

        return $this->publicMetadata($item);
    }

    public function isVisibleCompetency(int $grade, string $key): bool
    {
        return PracticeCurriculum::isVisible($grade, $key);
    }

    public function generate(int $grade, string $competencyKey, int $difficulty, ?int $seed = null): array
    {
        $rawMeta = PracticeCurriculum::find($grade, $competencyKey);
        if (!$rawMeta) {
            throw new InvalidArgumentException('Unknown practice competency.');
        }

        $meta = $this->publicMetadata($rawMeta);
        $difficulty = max(1, min(5, $difficulty));
        $options = $rawMeta['options'] ?? [];

        $problem = match ($rawMeta['template']) {
            'addition' => $this->addition($difficulty, $seed, (int) ($options['limit'] ?? 100)),
            'subtraction' => $this->subtraction($difficulty, $seed, (int) ($options['limit'] ?? 100)),
            'comparison' => $this->comparison($difficulty, $seed, (int) ($options['limit'] ?? 100)),
            'place-value' => $this->placeValue($difficulty, $seed, (int) ($options['digits'] ?? 2)),
            'multiplication' => $this->multiplicationRange(
                $difficulty,
                $seed,
                (int) ($options['minimum'] ?? 1),
                (int) ($options['maximum'] ?? 10),
                (bool) ($options['groups'] ?? false),
                (array) ($options['factors'] ?? []),
                (int) ($options['counter_limit'] ?? 12)
            ),
            'division' => $this->divisionRange(
                $difficulty,
                $seed,
                (int) ($options['minimum'] ?? 1),
                (int) ($options['maximum'] ?? 10),
                (array) ($options['factors'] ?? []),
                (int) ($options['counter_limit'] ?? 12)
            ),
            'geometry' => $this->geometryProblem((string) $options['kind'], $difficulty, $seed),
            'number-sense' => $this->numberSenseProblem((string) $options['kind'], $difficulty, $seed, $options),
            'measurement' => $this->measurementProblem((string) $options['kind'], $difficulty, $seed),
            'data' => $this->dataProblem((string) $options['kind'], $difficulty, $seed, $options),
            'patterns' => $this->patternProblem((string) $options['kind'], $difficulty, $seed),
            'fractions' => $this->fractionProblem((string) $options['kind'], $difficulty, $seed),
            'money' => $this->moneyProblem((string) $options['kind'], $difficulty, $seed, (int) ($options['limit'] ?? 100)),
            'time' => $this->timeProblem((string) $options['kind'], $difficulty, $seed),
            'transformations' => $this->transformationProblem((string) $options['kind'], $difficulty, $seed),
            'arithmetic' => $this->arithmeticProblem((string) $options['kind'], $difficulty, $seed),
            'decimals' => $this->decimalProblem((string) $options['kind'], $difficulty, $seed, (int) ($options['places'] ?? 2)),
            'number-theory' => $this->numberTheoryProblem((string) $options['kind'], $difficulty, $seed),
            'ratios' => $this->ratioProblem((string) $options['kind'], $difficulty, $seed),
            'exponents' => $this->exponentProblem($difficulty, $seed),
            'circles' => $this->circleProblem((string) $options['kind'], $difficulty, $seed),
            'legacy-integers' => $this->integers($difficulty, $seed),
            'legacy-expressions' => $this->expressions($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown practice template.'),
        };

        $problem = $this->varyPresentation($problem, $seed);

        return $meta + ['difficulty' => $difficulty] + $problem;
    }

    private function publicMetadata(array $item): array
    {
        unset($item['template'], $item['options']);

        return $item;
    }

    private function addition(int $difficulty, ?int $seed, int $limit): array
    {
        $workingLimit = min($limit, max(10, (int) ceil($limit * ($difficulty + 1) / 6)));
        $minimumTotal = min($workingLimit - 1, max(7, (int) floor($workingLimit * (0.45 + ($difficulty * 0.07)))));
        $total = $this->number($minimumTotal, $workingLimit, 1, $seed);
        $minimumPart = $difficulty >= 4 && $total >= 16 ? 4 : 2;
        $a = $this->number($minimumPart, max($minimumPart, $total - $minimumPart), 2, $seed);
        $b = $total - $a;
        if ($limit === 100) {
            $totalTens = (int) floor($total / 10);
            $totalOnes = $total % 10;
            $aTens = $this->number(0, max(0, $totalTens - 1), 201, $seed);
            $aOnes = $this->number(0, $totalOnes, 202, $seed);
            $a = ($aTens * 10) + $aOnes;
            if ($a < 2) {
                $a = $total >= 20 ? 10 : min(2, $total);
            }
            $b = $total - $a;
        }

        if ($difficulty >= 4 && $limit >= 1000) {
            $third = $this->number(10, max(10, (int) floor($total / 4)), 211, $seed);
            $first = $this->number(10, max(10, $total - $third - 10), 212, $seed);
            $second = $total - $first - $third;
            $prompt = $this->promptVariant([
                "A reading drive logged {$first} pages before lunch, {$second} after lunch, and {$third} at home. How many pages were read altogether?",
                "Three supply boxes hold {$first}, {$second}, and {$third} markers. Find the total number of markers without counting them one by one.",
                "A team needs the combined total of {$first} + {$second} + {$third}. What number completes its record?",
            ], $seed, 213);

            return $this->numberProblem(
                $prompt,
                $total,
                ['Combine two amounts first, then add the third.', 'Estimate the total before calculating to check that the result is reasonable.'],
                "{$first} + {$second} + {$third} = {$total}."
            );
        }

        $variantMinimum = $difficulty >= 5 ? 2 : ($difficulty >= 4 ? 1 : 0);
        $variant = $this->number($variantMinimum, 4, 214, $seed);
        $answer = match ($variant) {
            2, 3 => $a,
            4 => $b,
            default => $total,
        };
        $prompt = match ($variant) {
            0 => "A tray had {$a} counters. After {$b} more were added, how many counters were on the tray?",
            1 => "Two classes collected {$a} and {$b} reusable bottles. What total should be entered in their shared record?",
            2 => "Some story cards were on a shelf. After {$b} more were added, there were {$total}. How many cards were there at first?",
            3 => "Find the missing part that balances the number sentence: __ + {$b} = {$total}.",
            default => "A two-round target is {$total} points. The first round earned {$a}. How many points must the second round earn to reach the target exactly?",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                match ($variant) {
                    2, 3 => "Use the total {$total} and subtract the known part {$b}.",
                    4 => "Use the target {$total} and subtract the first-round score {$a}.",
                    default => "Combine the two parts, {$a} and {$b}.",
                },
                'Use the inverse operation to check the missing part or total.',
            ],
            match ($variant) {
                2, 3 => "{$total} − {$b} = {$a}, so {$a} + {$b} = {$total}.",
                4 => "{$total} − {$a} = {$b}, so the second round needs {$b} points.",
                default => "{$a} + {$b} = {$total}.",
            }
        );
    }

    private function subtraction(int $difficulty, ?int $seed, int $limit): array
    {
        $workingLimit = min($limit, max(10, (int) ceil($limit * ($difficulty + 1) / 6)));
        $minimumStart = min($workingLimit, max(9, (int) floor($workingLimit * (0.5 + ($difficulty * 0.06)))));
        $start = $this->number($minimumStart, $workingLimit, 3, $seed);
        $minimumRemoved = $difficulty >= 4 && $limit >= 1000
            ? 20
            : ($difficulty >= 4 && $start >= 20 ? 5 : 2);
        $removed = $this->number($minimumRemoved, max($minimumRemoved, $start - 2), 4, $seed);
        if ($limit === 100) {
            $startTens = (int) floor($start / 10);
            $startOnes = $start % 10;
            $removedTens = $this->number(0, max(0, $startTens - 1), 203, $seed);
            $removedOnes = $this->number(0, $startOnes, 204, $seed);
            $removed = ($removedTens * 10) + $removedOnes;
            if ($removed < 2) {
                $removed = $start >= 20 ? 10 : min(2, $start - 1);
            }
        }
        $remaining = $start - $removed;

        if ($difficulty >= 4 && $limit >= 1000) {
            $returned = $this->number(10, max(10, min($removed - 1, (int) floor($start / 5))), 215, $seed);
            $answer = $remaining + $returned;
            $prompt = $this->promptVariant([
                "A library began with {$start} loan cards. It issued {$removed}, then {$returned} unused cards were returned. How many cards are at the library now?",
                "A warehouse had {$start} units, shipped {$removed}, and received {$returned} units back. Find the final stock.",
                "Evaluate the change in this inventory story: start with {$start}, remove {$removed}, then restore {$returned}. What remains?",
            ], $seed, 216);

            return $this->numberProblem(
                $prompt,
                $answer,
                ['Subtract the amount that left first.', 'Add back only the amount that returned.'],
                "{$start} − {$removed} + {$returned} = {$answer}."
            );
        }

        $variantMinimum = $difficulty >= 5 ? 2 : ($difficulty >= 4 ? 1 : 0);
        $variant = $this->number($variantMinimum, 4, 217, $seed);
        $answer = match ($variant) {
            2, 3 => $start,
            4 => $removed,
            default => $remaining,
        };
        $prompt = match ($variant) {
            0 => "A box held {$start} tokens. After {$removed} were used, how many tokens remained?",
            1 => "Team Nova scored {$start} points and Team Orbit scored {$removed}. By how many points did Nova lead?",
            2 => "A shelf has {$remaining} books after {$removed} books were borrowed. How many books were there before borrowing?",
            3 => "Find the starting value: __ − {$removed} = {$remaining}.",
            default => "A container started with {$start} counters and ended with {$remaining}. How many counters were taken away?",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                match ($variant) {
                    2, 3 => "Add the removed part {$removed} back to the remaining {$remaining}.",
                    4 => "Compare the starting amount {$start} with the remaining {$remaining}.",
                    default => "Subtract {$removed} from {$start}.",
                },
                'Check that the removed part and the remaining part recombine to make the start.',
            ],
            "{$start} − {$removed} = {$remaining}, and {$remaining} + {$removed} = {$start}."
        );
    }

    private function comparison(int $difficulty, ?int $seed, int $limit): array
    {
        $workingLimit = min($limit, max(20, (int) ceil($limit * ($difficulty + 1) / 6)));
        $a = $this->number(4, $workingLimit, 5, $seed);
        $b = $this->number(4, $workingLimit, 6, $seed);
        if ($this->number(0, 4, 7, $seed) !== 0 && $a === $b) {
            $b = ($b + 1) % ($workingLimit + 1);
        }
        $answer = $a < $b ? '<' : ($a > $b ? '>' : '=');
        $variant = $this->number($difficulty >= 4 ? 1 : 0, 3, 218, $seed);
        $offset = $this->number(1, max(1, min(9, $difficulty + 3)), 219, $seed);
        $leftBase = max(0, $a - $offset);
        $rightBase = max(0, $b - $offset);
        $prompt = match ($variant) {
            0 => "Choose the symbol that makes this true: {$a} __ {$b}.",
            1 => "Box A has {$leftBase} counters and receives {$offset} more. Box B has {$b} counters. Compare their final amounts: A __ B.",
            2 => "Compare the two decomposed values: ({$leftBase} + {$offset}) __ {$b}.",
            default => "A score is {$a}. Another score is made from {$rightBase} + {$offset}. Which symbol correctly compares the first score with the second?",
        };

        return $this->choiceProblem(
            $prompt,
            ['<', '=', '>'],
            $answer,
            [
                'Work out any composed value before comparing.',
                'The open side of the greater-than symbol faces the larger number.',
            ],
            "{$a} {$answer} {$b} is the true comparison."
        );
    }

    private function placeValue(int $difficulty, ?int $seed, int $digits): array
    {
        $ones = $this->number(1, 9, 8, $seed);
        $tens = $this->number(1, 9, 9, $seed);
        $hundreds = $digits >= 3 ? $this->number(1, 9, 10, $seed) : 0;
        $number = ($hundreds * 100) + ($tens * 10) + $ones;
        $places = $digits >= 3 ? [1, 10, 100] : [1, 10];
        $place = $places[$this->number(0, count($places) - 1, 11, $seed)];
        $digit = (int) floor($number / $place) % 10;
        $placeName = match ($place) { 100 => 'hundreds', 10 => 'tens', default => 'ones' };
        $answer = $digit * $place;
        $variant = $this->number($difficulty >= 4 ? 1 : 0, 4, 220, $seed);
        $expandedParts = array_values(array_filter([
            $hundreds > 0 ? $hundreds * 100 : 0,
            $tens * 10,
            $ones,
        ], fn (int $part): bool => $part !== 0));
        $knownParts = array_values(array_filter($expandedParts, fn (int $part): bool => $part !== $answer));
        $prompt = match ($variant) {
            0 => "Which value is contributed by the digit {$digit} in {$number}?",
            1 => "The number {$number} is split into " . implode(' + ', $knownParts) . ' + __. What value is missing?',
            2 => "Which digit in {$number} has a value of {$answer}?",
            3 => $digits >= 3
                ? "A number has {$hundreds} hundreds, {$tens} tens, and {$ones} ones. What number is represented?"
                : "A number has {$tens} tens and {$ones} ones. What number is represented?",
            default => "A place-value machine shows {$number}. If its {$placeName} part is removed, what value was removed?",
        };
        $problemAnswer = match ($variant) {
            2 => $digit,
            3 => $number,
            default => $answer,
        };

        return $this->numberProblem(
            $prompt,
            $problemAnswer,
            [
                "Decompose {$number} into hundreds, tens, and ones.",
                $variant === 2 ? "Find which digit contributes {$answer}." : "The {$placeName} contribution is digit × {$place}.",
            ],
            "{$number} contains {$digit} in the {$placeName} place, where it contributes {$answer}."
        );
    }

    private function multiplication(int $difficulty, ?int $seed, int $maximum, bool $groups = false): array
    {
        $factorLimit = min($maximum, 3 + ($difficulty * 2));
        $a = $this->number(2, max(2, $factorLimit), 12, $seed);
        $b = $this->number(2, max(3, $factorLimit), 13, $seed);
        $answer = $a * $b;
        $prompt = $groups
            ? "There are {$a} groups with {$b} objects in each group. How many objects are there?"
            : "Power the product: {$a} × {$b} = ?";

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                "Add {$b} repeatedly {$a} times.",
                "You can also think of {$a} rows with {$b} objects in every row.",
            ],
            "{$a} groups of {$b} make {$a} × {$b} = {$answer}."
        );
    }

    private function division(int $difficulty, ?int $seed): array
    {
        $divisor = $this->number(2, min(12, 3 + ($difficulty * 2)), 14, $seed);
        $quotient = $this->number(2, min(12, 4 + ($difficulty * 2)), 15, $seed);
        $dividend = $divisor * $quotient;

        return $this->numberProblem(
            "Dock the quotient: {$dividend} ÷ {$divisor} = ?",
            $quotient,
            [
                "Ask how many groups of {$divisor} fit into {$dividend}.",
                "Find the number that makes {$divisor} × ? = {$dividend}.",
            ],
            "{$divisor} × {$quotient} = {$dividend}, so {$dividend} ÷ {$divisor} = {$quotient}."
        );
    }

    private function unitFractions(int $difficulty, ?int $seed): array
    {
        $denominators = [2, 3, 4, 5, 6, 8];
        $availableCount = min(count($denominators), 2 + $difficulty);
        $aIndex = $this->number(0, $availableCount - 1, 16, $seed);
        $bIndex = $this->number(0, $availableCount - 2, 17, $seed);
        if ($bIndex >= $aIndex) {
            $bIndex++;
        }
        $a = $denominators[$aIndex];
        $b = $denominators[$bIndex];
        $first = "1/{$a}";
        $second = "1/{$b}";
        $answer = $a < $b ? $first : $second;
        $prompt = $this->promptVariant([
            "Which unit fraction is greater: {$first} or {$second}?",
            "Two equal wholes are divided into {$a} parts and {$b} parts. Which single piece is larger: {$first} or {$second}?",
            "Select the larger unit fraction from {$first} and {$second}.",
        ], $seed, 225);

        return $this->choiceProblem(
            $prompt,
            [$first, $second, 'They are equal'],
            $answer,
            [
                'For unit fractions, imagine one equal piece of the same whole.',
                'A smaller denominator creates a larger piece.',
            ],
            "{$answer} is greater because its denominator is smaller, so each piece is larger."
        );
    }

    private function perimeter(int $difficulty, ?int $seed): array
    {
        $length = $this->number(3, 6 + ($difficulty * 3), 18, $seed);
        $width = $this->number(2, 4 + ($difficulty * 2), 19, $seed);
        $perimeter = 2 * ($length + $width);
        $variantMinimum = $difficulty >= 5 ? 4 : 0;
        $variant = $this->number($variantMinimum, $difficulty >= 4 ? 5 : 3, 214, $seed);

        if ($variant === 4) {
            $gate = $this->number(1, max(1, min($width - 1, 2 + $difficulty)), 215, $seed);
            $answer = $perimeter - $gate;

            return $this->numberProblem(
                "A {$length} m by {$width} m rectangular garden needs fencing, except for a {$gate} m gate opening. How many meters of fence are needed?",
                $answer,
                ['Find the complete perimeter first.', 'Subtract the gate opening because it is not fenced.'],
                "2 × ({$length} + {$width}) − {$gate} = {$answer} meters of fence."
            );
        }

        if ($variant === 5) {
            return $this->numberProblem(
                "A rectangle has perimeter {$perimeter} m and length {$length} m. What is its width?",
                $width,
                ['Half the perimeter equals length + width.', 'Subtract the known length from half the perimeter.'],
                "{$perimeter} ÷ 2 − {$length} = {$width} meters."
            );
        }

        $answer = $perimeter;
        $prompt = match ($variant) {
            0 => "A rectangle is {$length} m long and {$width} m wide. What is its perimeter in meters?",
            1 => "A rectangular garden measures {$length} m by {$width} m. How many meters of fence surround it?",
            2 => "A frame has two sides of {$length} m and two sides of {$width} m. What is the total distance around it?",
            default => "Calculate the perimeter of a {$length} m by {$width} m rectangle.",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                'Perimeter is the distance around all four sides.',
                "Use 2 × ({$length} + {$width}).",
            ],
            "2 × ({$length} + {$width}) = 2 × " . ($length + $width) . " = {$answer} meters."
        );
    }

    private function multiDigitAddition(int $difficulty, ?int $seed): array
    {
        $maximum = 200 + ($difficulty * 800);
        $a = $this->number(100, $maximum, 20, $seed);
        $b = $this->number(100, $maximum, 21, $seed);
        $answer = $a + $b;

        return $this->numberProblem(
            "Complete the navigation total: {$a} + {$b} = ?",
            $answer,
            [
                'Line up the ones, tens, hundreds, and thousands places.',
                'Add from right to left and regroup whenever a column reaches 10.',
            ],
            "Adding the aligned place values gives {$a} + {$b} = {$answer}."
        );
    }

    private function multiDigitMultiplication(int $difficulty, ?int $seed): array
    {
        $aMaximum = [1 => 60, 2 => 120, 3 => 250, 4 => 500, 5 => 1000][$difficulty];
        $a = $this->number(12, $aMaximum, 22, $seed);
        $bMaximum = $difficulty >= 5 ? 50 : ($difficulty >= 4 ? 25 : 9);
        $b = $this->number(2, $bMaximum, 23, $seed);
        $product = $a * $b;
        $reserved = $difficulty >= 4 ? $this->number(1, max(1, min($product - 1, $b * 2)), 24, $seed) : 0;
        $answer = $product - $reserved;
        $prompt = $this->promptVariant([
            $reserved > 0
                ? "A warehouse packs {$b} items into each of {$a} crates, then sets aside {$reserved} items for inspection. How many packed items remain available?"
                : "A warehouse loads {$b} items into each of {$a} crates. How many items are loaded altogether?",
            $reserved > 0
                ? "An array has {$a} rows and {$b} positions per row, but {$reserved} positions are blocked. How many usable positions remain?"
                : "An array has {$a} rows and {$b} columns. How many positions are in the array?",
            $reserved > 0
                ? "Evaluate {$a} × {$b} − {$reserved}."
                : "Find the missing product in {$a} × {$b} = __.",
        ], $seed, 226);

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                "Break {$a} into place-value parts before multiplying by {$b}.",
                $reserved > 0 ? "Subtract {$reserved} after finding the product." : 'Add the partial products to get the final product.',
            ],
            $reserved > 0
                ? "{$a} × {$b} = {$product}; {$product} − {$reserved} = {$answer}."
                : "The partial products combine to make {$a} × {$b} = {$answer}."
        );
    }

    private function equivalentFractions(int $difficulty, ?int $seed): array
    {
        $numerator = $this->number(1, 4 + $difficulty, 24, $seed);
        $denominator = $numerator + $this->number(1, 5 + $difficulty, 25, $seed);
        $scale = $this->number(2, 2 + $difficulty, 26, $seed);
        $scaledNumerator = $numerator * $scale;
        $answer = $denominator * $scale;
        $prompt = $this->promptVariant([
            "Complete the equivalent fraction: {$numerator}/{$denominator} = {$scaledNumerator}/?",
            "The fraction {$numerator}/{$denominator} is scaled to have numerator {$scaledNumerator}. What is its new denominator?",
            "Fill the blank so the fractions are equal: {$scaledNumerator}/__ = {$numerator}/{$denominator}.",
        ], $seed, 227);

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                "{$numerator} was multiplied by {$scale} to make {$scaledNumerator}.",
                "Multiply the denominator {$denominator} by the same number.",
            ],
            "Multiplying both parts by {$scale} gives {$scaledNumerator}/{$answer}."
        );
    }

    private function angles(int $difficulty, ?int $seed): array
    {
        $types = ['acute', 'right', 'obtuse'];
        $type = $types[$this->number(0, count($types) - 1, 27, $seed)];
        $degrees = match ($type) {
            'acute' => $this->number(15, 85, 28, $seed),
            'right' => 90,
            default => $this->number(95, min(175, 120 + ($difficulty * 10)), 29, $seed),
        };
        $variantMinimum = $difficulty >= 5 ? 3 : ($difficulty >= 4 ? 2 : 0);
        $variant = $this->number($variantMinimum, $difficulty >= 3 ? 4 : 2, 228, $seed);

        if ($variant === 3) {
            $part = $this->number(15, 75, 229, $seed);
            $answer = 90 - $part;

            return $this->numberProblem(
                "A right angle is split into angles of {$part}° and an unknown measure. What is the missing angle?",
                $answer,
                ['A right angle totals 90°.', 'Subtract the known part from 90°.'],
                "90° − {$part}° = {$answer}°."
            );
        }

        if ($variant === 4) {
            $part = $this->number(35, 145, 230, $seed);
            $answer = 180 - $part;

            return $this->numberProblem(
                "Two adjacent angles form a straight angle. One measures {$part}°. What is the other angle?",
                $answer,
                ['A straight angle measures 180°.', 'Subtract the known angle from 180°.'],
                "180° − {$part}° = {$answer}°."
            );
        }

        $prompt = match ($variant) {
            0 => "Classify an angle that measures {$degrees}°.",
            1 => "A protractor shows {$degrees}°. Which angle type matches the measurement?",
            default => "A learner calls a {$degrees}° angle right. Which correct classification should replace that label?",
        };

        return $this->choiceProblem(
            $prompt,
            ['Acute', 'Right', 'Obtuse'],
            ucfirst($type),
            [
                'Compare the angle measure with 90°.',
                'Acute is below 90°, right is exactly 90°, and obtuse is between 90° and 180°.',
            ],
            "An angle measuring {$degrees}° is {$type}."
        );
    }

    private function decimalAddition(int $difficulty, ?int $seed): array
    {
        $aCents = $this->number(10, 100 + ($difficulty * 180), 30, $seed);
        $bCents = $this->number(10, 100 + ($difficulty * 180), 31, $seed);
        $answerCents = $aCents + $bCents;
        $a = $this->decimal($aCents);
        $b = $this->decimal($bCents);
        $answer = $this->decimal($answerCents);

        return $this->numberProblem(
            "Align the decimals: {$a} + {$b} = ?",
            $answer,
            [
                'Write the numbers so their decimal points line up.',
                'Add each place-value column from right to left.',
            ],
            "With the decimal points aligned, {$a} + {$b} = {$answer}."
        );
    }

    private function fractionNumeratorAddition(int $difficulty, ?int $seed): array
    {
        $denominator = $this->number(3, 7 + $difficulty, 32, $seed);
        $a = $this->number(1, $denominator - 1, 33, $seed);
        $b = $this->number(1, $denominator - 1, 34, $seed);
        $answer = $a + $b;

        return $this->numberProblem(
            "Find the missing numerator: {$a}/{$denominator} + {$b}/{$denominator} = ?/{$denominator}",
            $answer,
            [
                'The denominators already match, so keep that denominator.',
                "Add only the numerators: {$a} + {$b}.",
            ],
            "{$a} + {$b} = {$answer}, so the sum is {$answer}/{$denominator}."
        );
    }

    private function volume(int $difficulty, ?int $seed): array
    {
        $length = $this->number(2, 3 + ($difficulty * 2), 35, $seed);
        $width = $this->number(2, 3 + $difficulty, 36, $seed);
        $height = $this->number(2, 3 + $difficulty, 37, $seed);
        $answer = $length * $width * $height;
        $prompt = $this->promptVariant([
            "A rectangular prism measures {$length} cm × {$width} cm × {$height} cm. What is its volume in cubic centimeters?",
            "A box has length {$length} cm, width {$width} cm, and height {$height} cm. How much space does it hold in cm³?",
            "Use V = l × w × h for a prism with l = {$length} cm, w = {$width} cm, and h = {$height} cm.",
            "How many 1 cm³ cubes fill a {$length} by {$width} by {$height} rectangular prism?",
        ], $seed, 229);

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                'Volume of a rectangular prism is length × width × height.',
                "Multiply {$length} × {$width}, then multiply that result by {$height}.",
            ],
            "{$length} × {$width} × {$height} = {$answer} cubic centimeters."
        );
    }

    private function orderOfOperations(int $difficulty, ?int $seed, bool $requireThreeOperations = false): array
    {
        $a = $this->number(2, 5 + $difficulty, 38, $seed);
        $b = $this->number(2, 5 + $difficulty, 39, $seed);
        $c = $this->number(2, 3 + $difficulty, 40, $seed);
        $d = $this->number(1, max(1, min(6, $a + $b - 1)), 41, $seed);
        $divisor = $this->number(2, 3 + $difficulty, 42, $seed);
        $quotient = $this->number(2, 4 + $difficulty, 43, $seed);
        $dividend = $divisor * $quotient;
        $variant = $requireThreeOperations
            ? [1, 2, 4, 5][$this->number(0, 3, 230, $seed)]
            : $this->number($difficulty >= 4 ? 1 : 0, min(4, 1 + $difficulty), 230, $seed);
        [$expression, $answer, $firstStep, $secondStep] = match ($variant) {
            0 => ["{$a} + {$b} × {$c}", $a + ($b * $c), "{$b} × {$c} = " . ($b * $c), "{$a} + " . ($b * $c) . ' = ' . ($a + ($b * $c))],
            1 => ["({$a} + {$b}) × {$c} − {$d}", (($a + $b) * $c) - $d, "{$a} + {$b} = " . ($a + $b), ($a + $b) . " × {$c} − {$d} = " . ((($a + $b) * $c) - $d)],
            2 => ["{$a} × {$b} + {$dividend} ÷ {$divisor}", ($a * $b) + $quotient, "{$a} × {$b} = " . ($a * $b) . " and {$dividend} ÷ {$divisor} = {$quotient}", ($a * $b) . " + {$quotient} = " . (($a * $b) + $quotient)],
            3 => ["{$a} + {$b} × ({$c} + {$d})", $a + ($b * ($c + $d)), "{$c} + {$d} = " . ($c + $d), "{$a} + {$b} × " . ($c + $d) . ' = ' . ($a + ($b * ($c + $d)))],
            4 => ["({$a} + {$b}) × ({$dividend} ÷ {$divisor})", ($a + $b) * $quotient, "{$a} + {$b} = " . ($a + $b) . " and {$dividend} ÷ {$divisor} = {$quotient}", ($a + $b) . " × {$quotient} = " . (($a + $b) * $quotient)],
            default => ["({$a} + {$b}) × {$c} + {$dividend} ÷ {$divisor}", (($a + $b) * $c) + $quotient, "{$a} + {$b} = " . ($a + $b) . " and {$dividend} ÷ {$divisor} = {$quotient}", ($a + $b) . " × {$c} + {$quotient} = " . ((($a + $b) * $c) + $quotient)],
        };
        $prompt = $this->promptVariant([
            "Evaluate {$expression} using the correct order of operations.",
            "A calculator entered {$expression}. What result should appear if MDAS/GMDAS is followed correctly?",
            "Find the value of {$expression}. Do not simply work from left to right.",
        ], $seed, 230);

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                'Complete grouping symbols first, then multiplication or division before addition or subtraction.',
                $firstStep,
            ],
            "First, {$firstStep}. Then {$secondStep}."
        );
    }

    private function ratios(int $difficulty, ?int $seed): array
    {
        $first = $this->number(1, 3 + $difficulty, 41, $seed);
        $second = $this->number(2, 5 + $difficulty, 42, $seed);
        if ($second === $first) {
            $second++;
        }
        $scale = $this->number(2, 3 + $difficulty, 43, $seed);
        $scaledFirst = $first * $scale;
        $scaledSecond = $second * $scale;
        $variantMinimum = $difficulty >= 5 ? 4 : 0;
        $variant = $this->number($variantMinimum, $difficulty >= 4 ? 6 : 3, 217, $seed);

        if ($variant === 4) {
            $total = ($first + $second) * $scale;

            return $this->numberProblem(
                "A club divides {$total} badges between blue and purple teams in the ratio {$first}:{$second}. How many badges does the purple team receive?",
                $scaledSecond,
                ['Count the total number of ratio parts.', 'Find the value of one part, then multiply by the purple part count.'],
                "The ratio has " . ($first + $second) . " parts; each part is {$scale}, so purple receives {$second} × {$scale} = {$scaledSecond}."
            );
        }

        if ($variant === 5) {
            $difference = abs($scaledSecond - $scaledFirst);

            return $this->numberProblem(
                "Two collections keep the ratio {$first}:{$second}. The scale factor is {$scale}. What is the difference between the two scaled collection sizes?",
                $difference,
                ['Scale both parts of the ratio.', 'Subtract the smaller scaled amount from the larger.'],
                "The scaled amounts are {$scaledFirst} and {$scaledSecond}, so the difference is {$difference}."
            );
        }

        if ($variant === 6) {
            $differenceParts = abs($second - $first);
            $difference = $differenceParts * $scale;
            $smallerPart = min($first, $second);
            $answer = $smallerPart * $scale;

            return $this->numberProblem(
                "Two collections are in the ratio {$first}:{$second}. The larger collection has {$difference} more items than the smaller one. How many items are in the smaller collection?",
                $answer,
                ['Find the difference between the two ratio parts.', 'Use the actual difference to find one ratio unit, then scale the smaller part.'],
                "The ratio parts differ by {$differenceParts}. Since {$difference} ÷ {$differenceParts} = {$scale}, the smaller collection has {$smallerPart} × {$scale} = {$answer} items."
            );
        }

        $answer = $scaledSecond;
        $prompt = match ($variant) {
            0 => "A ship uses {$first} blue crystals for every {$second} purple crystals. If it uses {$scaledFirst} blue crystals, how many purple crystals are needed?",
            1 => "A recipe uses {$first} cups of one ingredient for every {$second} cups of another. If the first amount becomes {$scaledFirst} cups, what is the matching second amount?",
            2 => "Complete the equivalent ratio: {$first}:{$second} = {$scaledFirst}:__",
            default => "A model has a ratio of {$first} small parts to {$second} large parts. With {$scaledFirst} small parts, how many large parts keep the ratio equivalent?",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                "Find what multiplied {$first} to make {$scaledFirst}.",
                "Multiply {$second} by that same scale factor, {$scale}.",
            ],
            "The ratio was scaled by {$scale}, so {$second} × {$scale} = {$answer} purple crystals."
        );
    }

    private function percentages(int $difficulty, ?int $seed): array
    {
        $percents = [10, 20, 25, 50, 75];
        $available = array_slice($percents, 0, min(count($percents), 2 + $difficulty));
        $percent = $available[$this->number(0, count($available) - 1, 44, $seed)];
        $whole = $this->number(1, 5 + ($difficulty * 2), 45, $seed) * 20;
        $part = intdiv($percent * $whole, 100);
        $variantMinimum = $difficulty >= 5 ? 4 : 0;
        $variant = $this->number($variantMinimum, $difficulty >= 4 ? 6 : ($difficulty >= 3 ? 4 : 3), 218, $seed);

        if ($variant === 4) {
            $answer = $whole - $part;

            return $this->numberProblem(
                "A ₱{$whole} item is discounted by {$percent}%. What is the sale price after the discount?",
                $answer,
                ['Find the discount amount first.', 'Subtract the discount from the original price.'],
                "{$percent}% of ₱{$whole} is ₱{$part}; ₱{$whole} − ₱{$part} = ₱{$answer}."
            );
        }

        if ($variant === 5) {
            return $this->numberProblem(
                "{$part} learners represent {$percent}% of a group. How many learners are in the whole group?",
                $whole,
                ["Write {$percent}% as {$percent}/100.", 'Divide the known part by the decimal form of the percentage.'],
                "{$part} ÷ " . $this->formatNumber($percent / 100, 2) . " = {$whole} learners."
            );
        }

        if ($variant === 6) {
            $increased = $whole + $part;
            $increasedPercent = 100 + $percent;

            return $this->numberProblem(
                "After a {$percent}% increase, a collection has {$increased} items. How many items were in the collection before the increase?",
                $whole,
                ["The new collection is {$increasedPercent}% of the original.", "Divide {$increased} by {$increasedPercent}/100 to recover 100%."],
                "{$increased} ÷ " . $this->formatNumber($increasedPercent / 100, 2) . " = {$whole} items."
            );
        }

        $answer = $part;
        $prompt = match ($variant) {
            0 => "Calculate {$percent}% of {$whole}.",
            1 => "A group has {$whole} learners, and {$percent}% completed a challenge. How many learners completed it?",
            2 => "A ₱{$whole} item is discounted by {$percent}%. What is the discount amount in pesos?",
            default => "Complete the percentage statement: {$percent}/100 × {$whole} = __",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                "Convert {$percent}% to {$percent}/100.",
                "Multiply {$whole} by {$percent}, then divide by 100.",
            ],
            "{$percent}% of {$whole} is ({$percent} × {$whole}) ÷ 100 = {$answer}."
        );
    }

    private function integers(int $difficulty, ?int $seed): array
    {
        $range = 5 + ($difficulty * 5);
        $a = $this->number(-$range, $range, 46, $seed);
        $b = $this->number(-$range, $range, 47, $seed);
        $answer = $a + $b;
        $bDisplay = $b < 0 ? "({$b})" : (string) $b;

        return $this->numberProblem(
            "Stabilize the temperature: {$a} + {$bDisplay} = ?",
            $answer,
            [
                'On a number line, positive values move right and negative values move left.',
                'If the signs differ, subtract their absolute values and keep the sign of the larger absolute value.',
            ],
            "Moving {$b} units from {$a} lands on {$answer}."
        );
    }

    private function expressions(int $difficulty, ?int $seed): array
    {
        $x = $this->number(1, 8 + ($difficulty * 4), 48, $seed);
        $addend = $this->number(2, 8 + ($difficulty * 3), 49, $seed);
        $total = $x + $addend;

        return $this->numberProblem(
            "Solve for x: x + {$addend} = {$total}",
            $x,
            [
                "Undo adding {$addend} by subtracting {$addend} from both sides.",
                "Calculate {$total} − {$addend}.",
            ],
            "x = {$total} − {$addend} = {$x}. Checking: {$x} + {$addend} = {$total}."
        );
    }

    private function multiplicationRange(
        int $difficulty,
        ?int $seed,
        int $minimum,
        int $maximum,
        bool $groups = false,
        array $factors = [],
        int $counterLimit = 12
    ): array {
        $minimum = max(1, min($minimum, $maximum));
        if ($factors !== []) {
            $available = array_values(array_filter($factors, fn ($factor): bool => is_int($factor) && $factor > 0));
            $a = $available !== []
                ? $available[$this->number(0, count($available) - 1, 50, $seed)]
                : $this->number($minimum, $maximum, 50, $seed);
        } else {
            $factorLimit = max($minimum, min($maximum, $minimum + $difficulty + 2));
            $a = $this->number($minimum, $factorLimit, 50, $seed);
        }
        $counterMaximum = min(max(2, $counterLimit), 4 + ($difficulty * 2));
        $counterMinimum = min($counterMaximum, $difficulty >= 5 ? 6 : ($difficulty >= 3 ? 3 : 2));
        $b = $this->number($counterMinimum, $counterMaximum, 51, $seed);
        $product = $a * $b;
        $variantMaximum = $difficulty >= 4 ? 5 : 3;
        $variantMinimum = $difficulty >= 5 ? 3 : ($difficulty >= 4 ? 2 : 0);
        $variant = $this->number($variantMinimum, $variantMaximum, 215, $seed);
        $extra = $this->number(1, max(2, $a + $difficulty), 216, $seed);
        $answer = match ($variant) {
            3 => $b,
            4 => max(0, ($a - 1) * $b),
            5 => $product + $extra,
            default => $product,
        };
        $prompt = match ($variant) {
            0 => $groups
                ? "Build {$a} equal groups with {$b} counters in each. How many counters are needed altogether?"
                : "Find the total represented by {$a} equal jumps of {$b} on a number line.",
            1 => "An array has {$a} rows with {$b} objects in every row. How many objects are in the complete array?",
            2 => "A store fills {$a} packs with {$b} items per pack. What total appears on its stock record?",
            3 => "A display has {$product} objects arranged in {$a} equal rows. How many objects must be in each row?",
            4 => "There are {$a} packs of {$b} cards, but one whole pack is reserved. How many cards are available to use?",
            default => "A hall has {$a} rows of {$b} seats plus {$extra} separate chairs. How many places are available altogether?",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                $variant === 3
                    ? "Use the inverse fact {$product} ÷ {$a}."
                    : "First find {$a} × {$b}.",
                match ($variant) {
                    4 => 'Remove one full group from the product.',
                    5 => "Add the {$extra} separate chairs after finding the array total.",
                    default => 'Use repeated addition or a known multiplication fact to check.',
                },
            ],
            match ($variant) {
                3 => "{$product} ÷ {$a} = {$b} objects in each row.",
                4 => "{$a} × {$b} = {$product}; reserving one group of {$b} leaves {$answer}.",
                5 => "{$a} × {$b} + {$extra} = {$answer}.",
                default => "{$a} groups of {$b} make {$product}.",
            }
        );
    }

    private function divisionRange(
        int $difficulty,
        ?int $seed,
        int $minimum,
        int $maximum,
        array $factors = [],
        int $counterLimit = 12
    ): array {
        $minimum = max(1, min($minimum, $maximum));
        if ($factors !== []) {
            $available = array_values(array_filter($factors, fn ($factor): bool => is_int($factor) && $factor > 0));
            $divisor = $available !== []
                ? $available[$this->number(0, count($available) - 1, 52, $seed)]
                : $this->number($minimum, $maximum, 52, $seed);
        } else {
            $divisor = $this->number($minimum, max($minimum, min($maximum, $minimum + $difficulty + 2)), 52, $seed);
        }
        $quotientMaximum = min(max(2, $counterLimit), 4 + ($difficulty * 2));
        $quotientMinimum = min($quotientMaximum, $difficulty >= 5 ? 6 : ($difficulty >= 3 ? 3 : 2));
        $quotient = $this->number($quotientMinimum, $quotientMaximum, 53, $seed);
        $dividend = $divisor * $quotient;
        $variantMaximum = $difficulty >= 4 ? 5 : 3;
        $variantMinimum = $difficulty >= 5 ? 3 : ($difficulty >= 4 ? 2 : 0);
        $variant = $this->number($variantMinimum, $variantMaximum, 217, $seed);
        $extraGroups = $this->number(1, 2 + (int) floor($difficulty / 2), 218, $seed);
        $largerDividend = $dividend + ($divisor * $extraGroups);
        $answer = match ($variant) {
            3 => $divisor,
            4 => max(1, $quotient - 1),
            5 => $quotient + $extraGroups,
            default => $quotient,
        };
        $prompt = match ($variant) {
            0 => "Find how many groups of {$divisor} are contained in {$dividend}.",
            1 => "Share {$dividend} objects equally among {$divisor} learners. How many objects does each learner receive?",
            2 => "Pack {$dividend} items into groups of {$divisor}. How many complete groups can be made?",
            3 => "A total of {$dividend} objects is split into {$quotient} equal groups. How many objects are in each group?",
            4 => "There are {$dividend} counters. Set aside one group of {$divisor}, then share the rest into groups of {$divisor}. How many groups remain?",
            default => "A collection of {$largerDividend} cards is packed in groups of {$divisor}. How many complete packs are made?",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                $variant === 3
                    ? "Use {$dividend} ÷ {$quotient} to find the group size."
                    : "Ask how many groups of {$divisor} fit into the amount.",
                $variant === 4
                    ? 'Account for the group that was set aside.'
                    : 'Use multiplication to check the quotient.',
            ],
            match ($variant) {
                3 => "{$dividend} ÷ {$quotient} = {$divisor} in each group.",
                4 => "{$dividend} ÷ {$divisor} = {$quotient}; setting aside one group leaves {$answer} groups.",
                5 => "{$largerDividend} ÷ {$divisor} = {$answer} complete packs.",
                default => "{$divisor} × {$quotient} = {$dividend}, so the quotient is {$quotient}.",
            }
        );
    }

    private function geometryProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'shapes-2d' => $this->shape2DProblem($difficulty, $seed),
            'circle-composites' => $this->circleCompositeProblem($difficulty, $seed),
            'lines-surfaces' => $this->linesAndSurfacesProblem($difficulty, $seed),
            'perimeter' => $this->perimeter($difficulty, $seed),
            'area-rectangles' => $this->rectangleArea($difficulty, $seed),
            'line-basics' => $this->lineBasicsProblem($difficulty, $seed),
            'line-relationships' => $this->lineRelationshipProblem($difficulty, $seed),
            'angles' => $this->angles($difficulty, $seed),
            'shape-properties' => $this->shapeProperties($difficulty, $seed),
            'composite-perimeter' => $this->compositePerimeter($difficulty, $seed),
            'symmetry' => $this->symmetryProblem($difficulty, $seed),
            'polygon-area' => $this->polygonArea($difficulty, $seed),
            'solid-figures' => $this->solidFigureProblem($difficulty, $seed),
            'surface-area' => $this->surfaceArea($difficulty, $seed),
            'volume-estimate' => $this->volumeWithUnitCubes($difficulty, $seed),
            'volume' => $this->volume($difficulty, $seed),
            'tessellation' => $this->tessellationProblem($difficulty, $seed),
            'composite-area-perimeter' => $this->compositeArea($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown geometry practice kind.'),
        };
    }

    private function shape2DProblem(int $difficulty, ?int $seed): array
    {
        $colors = ['red', 'blue', 'green', 'gold', 'purple'];
        $shapeData = [
            ['Triangle', 3, 3, false],
            ['Square', 4, 4, true],
            ['Rectangle', 4, 4, false],
        ];
        $shapeIndex = $this->number(0, 2, 190, $seed);
        [$shape, $sides, $corners, $allEqual] = $shapeData[$shapeIndex];
        $color = $colors[$this->number(0, count($colors) - 1, 191, $seed)];
        $variant = $this->number(0, 4, 200, $seed);

        if ($variant === 0) {
            $clue = $shape === 'Triangle'
                ? '3 straight sides and 3 corners'
                : ($shape === 'Square' ? '4 equal sides and 4 corners' : '4 corners, with one pair of opposite sides longer than the other pair');

            return $this->choiceProblem(
                "A {$color} mystery card has {$clue}. Which shape is on the card?",
                ['Triangle', 'Square', 'Rectangle'],
                $shape,
                ['Use every clue, not only the number of corners.', 'Compare the side lengths when two choices both have four sides.'],
                "Those properties identify a {$shape}."
            );
        }

        if ($variant === 1) {
            return $this->choiceProblem(
                "A learner sorts shapes by number of straight sides. Which pair belongs together in the 4-side group?",
                ['Square and rectangle', 'Triangle and square', 'Triangle and rectangle'],
                'Square and rectangle',
                ['Count every outside edge once.', 'A triangle has one fewer side than the other two shapes.'],
                'Squares and rectangles each have four straight sides.'
            );
        }

        if ($variant === 2) {
            $otherIndex = ($shapeIndex + $this->number(1, 2, 192, $seed)) % 3;
            [$otherShape, , $otherCorners] = $shapeData[$otherIndex];
            $answer = (string) ($corners + $otherCorners);

            return $this->choiceProblem(
                "A {$color} {$shape} card and a separate {$otherShape} card are placed side by side without touching. How many corners do the two cards have altogether?",
                [$answer, (string) max(1, $corners + $otherCorners - 1), (string) ($corners + $otherCorners + 1)],
                $answer,
                ['Count the corners on each complete shape.', 'Because the cards do not touch, no corner disappears.'],
                "The shapes have {$corners} + {$otherCorners} = {$answer} corners altogether."
            );
        }

        if ($variant === 3) {
            $base = $this->number(0, 1, 193, $seed) === 1 ? 'square' : 'rectangle';

            return $this->choiceProblem(
                "A {$color} {$base} is cut from one corner to the opposite corner. Which description fits the two pieces?",
                ['Two triangles', 'Two smaller circles', 'One square and one triangle'],
                'Two triangles',
                ['The cut is a diagonal.', 'Trace the three edges around each piece.'],
                "A diagonal divides the {$base} into two triangles."
            );
        }

        $firstColor = $colors[$this->number(0, count($colors) - 1, 194, $seed)];
        $secondColor = $colors[($this->number(0, count($colors) - 1, 195, $seed) + 1) % count($colors)];
        $thirdColor = $colors[($this->number(0, count($colors) - 1, 196, $seed) + 2) % count($colors)];
        if (count(array_unique([$firstColor, $secondColor, $thirdColor])) < 3) {
            [$firstColor, $secondColor, $thirdColor] = ['red', 'blue', 'green'];
        }

        return $this->choiceProblem(
            "The {$firstColor} card is a triangle, the {$secondColor} card is a square, and the {$thirdColor} card is a rectangle. Which card has 4 corners but does not require all 4 sides to be equal?",
            [ucfirst($firstColor), ucfirst($secondColor), ucfirst($thirdColor)],
            ucfirst($thirdColor),
            ['Eliminate the three-corner shape first.', 'Then compare the side rule for a square with the side rule for a rectangle.'],
            "The {$thirdColor} rectangle has four corners, while only opposite sides must match."
        );
    }

    private function circleCompositeProblem(int $difficulty, ?int $seed): array
    {
        $variant = $this->number(0, 4, 201, $seed);
        $wholes = $this->number(1, 1 + (int) ceil($difficulty / 2), 197, $seed);

        if ($variant === 0) {
            $totalQuarters = $wholes * 4;
            $given = $this->number(1, $totalQuarters - 1, 198, $seed);
            $answer = $totalQuarters - $given;

            return $this->numberProblem(
                "A design needs {$wholes} whole circle" . ($wholes === 1 ? '' : 's') . ". It already has {$given} quarter-circle pieces. How many more quarter-circle pieces are needed?",
                $answer,
                ['Each whole circle needs four quarters.', 'Find all quarters needed, then subtract the pieces already available.'],
                "{$wholes} × 4 = {$totalQuarters} quarters; {$totalQuarters} − {$given} = {$answer}."
            );
        }

        if ($variant === 1) {
            $halves = $this->number(1, 3, 199, $seed);
            $quarters = $this->number(1, 3, 200, $seed);
            $totalQuarterUnits = ($halves * 2) + $quarters;
            $answer = $this->fractionLabel($totalQuarterUnits, 4);

            return $this->choiceProblem(
                "A mosaic uses {$halves} half-circle piece" . ($halves === 1 ? '' : 's') . " and {$quarters} quarter-circle piece" . ($quarters === 1 ? '' : 's') . '. Together, they equal how many whole circles?',
                $this->fractionOptions($totalQuarterUnits, 4),
                $answer,
                ['Rewrite every half as two quarters.', 'Count all quarter units and simplify the fraction over 4.'],
                "The pieces total {$totalQuarterUnits}/4 of a circle, or {$answer}."
            );
        }

        if ($variant === 2) {
            $cutIntoQuarters = $this->number(0, 1, 201, $seed) === 1;
            $piecesPerCircle = $cutIntoQuarters ? 4 : 2;
            $answer = $wholes * $piecesPerCircle;
            $pieceName = $cutIntoQuarters ? 'quarter circles' : 'half circles';

            return $this->numberProblem(
                "Each of {$wholes} paper circle" . ($wholes === 1 ? ' is' : 's are') . " cut into {$piecesPerCircle} equal curved pieces. How many {$pieceName} are made altogether?",
                $answer,
                ['Find how many pieces come from one circle.', 'Multiply by the number of circles cut.'],
                "{$wholes} × {$piecesPerCircle} = {$answer} {$pieceName}."
            );
        }

        if ($variant === 3) {
            return $this->choiceProblem(
                "Which collection can be rearranged to make exactly {$wholes} whole circle" . ($wholes === 1 ? '' : 's') . '?',
                [
                    (2 * $wholes) . ' half circles',
                    (2 * $wholes) . ' quarter circles',
                    (3 * $wholes) . ' quarter circles',
                ],
                (2 * $wholes) . ' half circles',
                ['Two halves make one whole circle.', 'Check the total represented by every option.'],
                (2 * $wholes) . " halves make exactly {$wholes} whole circle" . ($wholes === 1 ? '.' : 's.')
            );
        }

        $squares = $this->number(1, 3, 202, $seed);
        $semicircles = $this->number(1, 3, 203, $seed);
        $answer = $squares + $semicircles;

        return $this->numberProblem(
            "A badge is composed of {$squares} separate square" . ($squares === 1 ? '' : 's') . " and {$semicircles} separate half-circle piece" . ($semicircles === 1 ? '' : 's') . '. How many basic shape pieces compose the badge?',
            $answer,
            ['Count complete square pieces.', 'Add the complete half-circle pieces.'],
            "{$squares} + {$semicircles} = {$answer} basic shape pieces."
        );
    }

    private function linesAndSurfacesProblem(int $difficulty, ?int $seed): array
    {
        $straight = ['edge of a ruler', 'side of a notebook', 'tightly stretched string', 'edge of a tile'];
        $curved = ['rainbow arc', 'rim of a round plate', 'curved bracelet edge', 'bend in a racetrack'];
        $flat = ['book cover', 'tabletop', 'cube face', 'floor tile'];
        $curvedSurfaces = ['outside of a ball', 'side of a can', 'orange peel', 'side of a cone'];
        $variant = $this->number(0, 4, 202, $seed);
        $index = $this->number(0, 3, 204, $seed);

        if ($variant === 0) {
            $askSurface = $this->number(0, 1, 205, $seed) === 1;
            $askCurved = $this->number(0, 1, 206, $seed) === 1;
            $object = $askSurface
                ? ($askCurved ? $curvedSurfaces[$index] : $flat[$index])
                : ($askCurved ? $curved[$index] : $straight[$index]);
            $answer = ($askCurved ? 'Curved ' : 'Straight ') . ($askSurface ? 'surface' : 'line');

            return $this->choiceProblem(
                "A learner traces the {$object}. Which description is most accurate?",
                ['Straight line', 'Curved line', 'Flat surface', 'Curved surface'],
                $answer,
                ['First decide whether the example is a path or a surface.', 'Then decide whether it bends.'],
                "The {$object} models a " . strtolower($answer) . '.'
            );
        }

        if ($variant === 1) {
            $straightObject = $straight[$index];
            $curvedIndex = $this->number(0, 3, 207, $seed);
            $curvedOne = $curved[$curvedIndex];
            $curvedTwo = $curved[($curvedIndex + 1) % 4];

            return $this->choiceProblem(
                'Which item does not belong with the other two when sorted by line type?',
                [$straightObject, $curvedOne, $curvedTwo],
                $straightObject,
                ['Picture the path made by each item.', 'Two bend while one continues without bending.'],
                "The {$straightObject} is straight; the other two examples are curved."
            );
        }

        if ($variant === 2) {
            $flatOne = $flat[$index];
            $flatTwo = $flat[($index + 1) % 4];
            $curvedOne = $curvedSurfaces[$this->number(0, 3, 209, $seed)];
            $answer = "{$flatOne} and {$flatTwo}";

            return $this->choiceProblem(
                'Which pair consists of two flat surfaces?',
                [$answer, "{$flatOne} and {$curvedOne}", "{$curvedOne} and {$flatTwo}"],
                $answer,
                ['A flat surface does not wrap or bend.', 'Check both objects in a pair.'],
                "Both the {$flatOne} and {$flatTwo} are flat."
            );
        }

        if ($variant === 3) {
            $objects = ['Cylinder', 'Can', 'Drinking glass'];
            $object = $objects[$this->number(0, count($objects) - 1, 210, $seed)];

            return $this->choiceProblem(
                "A {$object} has a flat circular base and a side that wraps around. Which surface description fits it?",
                ['Both flat and curved surfaces', 'Only flat surfaces', 'Only curved surfaces'],
                'Both flat and curved surfaces',
                ['Consider the base separately from the side.', 'Different parts of one object can have different surface types.'],
                "A {$object} combines flat circular surfaces with a curved side."
            );
        }

        $objects = [$flat[$index], $curvedSurfaces[$index], $flat[($index + 2) % 4], $curvedSurfaces[($index + 1) % 4]];

        return $this->choiceProblem(
            'The surfaces listed are ' . implode(', ', $objects) . '. How many are curved surfaces?',
            ['2', '1', '3'],
            '2',
            ['Classify each surface one at a time.', 'Count only surfaces that bend around an object.'],
            'Two of the four listed examples are curved surfaces.'
        );
    }

    private function lineBasicsProblem(int $difficulty, ?int $seed): array
    {
        $types = [
            ['Point', 0, 'one exact location with no length'],
            ['Line segment', 2, 'two endpoints and a fixed length'],
            ['Ray', 1, 'one endpoint and an arrow in one direction'],
            ['Line', 0, 'arrows in both directions and no endpoints'],
        ];
        $typeIndex = $this->number(0, 3, 211, $seed);
        [$type, $endpoints, $description] = $types[$typeIndex];
        $letters = ['A', 'B', 'C', 'D', 'P', 'Q', 'R', 'S'];
        $firstIndex = $this->number(0, count($letters) - 1, 212, $seed);
        $first = $letters[$firstIndex];
        $second = $letters[($firstIndex + $this->number(1, count($letters) - 1, 213, $seed)) % count($letters)];
        $variant = $this->number(0, 4, 203, $seed);

        if ($variant === 0) {
            return $this->choiceProblem(
                "A diagram is described as {$description}. Which geometric object should label the diagram?",
                ['Point', 'Line segment', 'Ray', 'Line'],
                $type,
                ['Count its endpoints first.', 'Then check whether either end continues with an arrow.'],
                "That description identifies a {$type}."
            );
        }

        if ($variant === 1) {
            $otherIndex = ($typeIndex + $this->number(1, 3, 214, $seed)) % 4;
            [$otherType, $otherEndpoints] = $types[$otherIndex];
            $answer = (string) ($endpoints + $otherEndpoints);

            return $this->choiceProblem(
                "A {$type} and a separate {$otherType} are drawn. How many endpoints do the two objects have altogether?",
                [$answer, (string) ($endpoints + $otherEndpoints + 1), (string) ($endpoints + $otherEndpoints + 2)],
                $answer,
                ['Recall the endpoint count for each object.', 'Arrows show continuation, not endpoints.'],
                "The endpoint counts are {$endpoints} and {$otherEndpoints}, for a total of {$answer}."
            );
        }

        if ($variant === 2) {
            $drawings = [
                "a dot labeled {$first}",
                "endpoints {$first} and {$second} joined with no arrows",
                "endpoint {$first} joined through {$second} with one arrow after {$second}",
                "a path through {$first} and {$second} with arrows at both ends",
            ];

            return $this->choiceProblem(
                "Which object is represented by {$drawings[$typeIndex]}?",
                ['Point', 'Line segment', 'Ray', 'Line'],
                $type,
                ['Dots mark endpoints or locations.', 'An arrow means the path continues forever in that direction.'],
                "The described drawing represents a {$type}."
            );
        }

        if ($variant === 3) {
            $statements = [
                'A line segment has two endpoints.',
                'A ray has two endpoints.',
                'A line stops at both ends.',
            ];

            return $this->choiceProblem(
                'A learner wrote three statements. Which statement is correct?',
                $statements,
                $statements[0],
                ['Check the meaning of an endpoint.', 'A line and a ray continue in at least one direction.'],
                'A line segment is the only listed object that stops at two endpoints.'
            );
        }

        $contexts = ['flashlight beam', 'starting line of a laser pointer', 'sunbeam through a window', 'path starting at a gate and continuing straight'];
        $context = $contexts[$this->number(0, count($contexts) - 1, 215, $seed)];

        return $this->choiceProblem(
            "A {$context} has a clear starting point and continues in one direction. Which geometric model fits best?",
            ['Ray', 'Line segment', 'Line'],
            'Ray',
            ['Identify the one starting endpoint.', 'The continuing direction is represented by an arrow.'],
            'A ray starts at one endpoint and continues in one direction.'
        );
    }

    private function lineRelationshipProblem(int $difficulty, ?int $seed): array
    {
        $relationships = ['Parallel', 'Perpendicular', 'Intersecting'];
        $relationship = $relationships[$this->number(0, 2, 216, $seed)];
        $contexts = [
            'two straight rails that stay equally spaced' => 'Parallel',
            'a vertical street meeting a horizontal street at a right angle' => 'Perpendicular',
            'two paths crossing without making a right angle' => 'Intersecting',
            'opposite edges of a rectangular window' => 'Parallel',
            'the upright and crossbar of a plus sign' => 'Perpendicular',
            'two diagonal strokes crossing at a non-right angle' => 'Intersecting',
        ];
        $variant = $this->number(0, 4, 204, $seed);

        if ($variant === 0) {
            $descriptions = [
                'Parallel' => 'remain the same distance apart even when extended',
                'Perpendicular' => 'meet and form four right angles',
                'Intersecting' => 'cross once but do not form right angles',
            ];

            return $this->choiceProblem(
                "Two lines {$descriptions[$relationship]}. How are the lines related?",
                ['Parallel', 'Perpendicular', 'Intersecting'],
                $relationship,
                ['Decide first whether the lines meet.', 'If they meet, check whether the angle is 90°.'],
                "The description matches {$relationship} lines."
            );
        }

        if ($variant === 1) {
            $rightAngle = $this->number(0, 1, 217, $seed) === 1;
            $angle = $rightAngle ? 90 : $this->number(25, 80, 218, $seed);
            $answer = $rightAngle ? 'Perpendicular' : 'Intersecting but not perpendicular';

            return $this->choiceProblem(
                "Two lines cross and one angle at the crossing measures {$angle}°. Which relationship is most precise?",
                ['Perpendicular', 'Parallel', 'Intersecting but not perpendicular'],
                $answer,
                ['Crossing lines are not parallel.', 'They are perpendicular only when a crossing angle is 90°.'],
                "A {$angle}° crossing makes the lines {$answer}."
            );
        }

        if ($variant === 2) {
            $horizontal = $this->number(0, 1, 219, $seed) === 1;
            $firstDescription = $horizontal ? 'both run left to right' : 'one runs left to right and one runs up and down';
            $answer = $horizontal ? 'Parallel' : 'Perpendicular';

            return $this->choiceProblem(
                "On a grid, two straight paths {$firstDescription}. They are placed so they " . ($horizontal ? 'never meet' : 'cross') . '. What relationship do they show?',
                ['Parallel', 'Perpendicular', 'Intersecting but not perpendicular'],
                $answer,
                ['Use each path’s direction.', 'Horizontal and vertical directions meet at right angles.'],
                "The paths are {$answer}."
            );
        }

        if ($variant === 3) {
            $keys = array_keys($contexts);
            $context = $keys[$this->number(0, count($keys) - 1, 220, $seed)];
            $answer = $contexts[$context];

            return $this->choiceProblem(
                "Which relationship is modeled by {$context}?",
                ['Parallel', 'Perpendicular', 'Intersecting'],
                $answer,
                ['Imagine extending both straight lines.', 'Look for equal spacing, a right angle, or another kind of crossing.'],
                "This example models {$answer} lines."
            );
        }

        $correct = "Perpendicular lines meet at 90°.";

        return $this->choiceProblem(
            'Which learner statement is always true?',
            [$correct, 'All intersecting lines are perpendicular.', 'Parallel lines meet when extended far enough.'],
            $correct,
            ['An “always true” statement must work for every example.', 'Perpendicular is a special kind of intersecting relationship.'],
            'Perpendicular lines always meet at right angles; other intersecting lines need not do so.'
        );
    }

    private function tessellationProblem(int $difficulty, ?int $seed): array
    {
        $tiles = [
            ['Equilateral triangle', 60, 6, true],
            ['Square', 90, 4, true],
            ['Regular hexagon', 120, 3, true],
            ['Regular pentagon', 108, 0, false],
            ['Circle', 0, 0, false],
        ];
        $tileIndex = $this->number(0, count($tiles) - 1, 221, $seed);
        [$tile, $angle, $copies, $tessellates] = $tiles[$tileIndex];
        $variant = $this->number(0, 4, 205, $seed);

        if ($variant === 0) {
            $answer = $tessellates ? 'Yes' : 'No';

            return $this->choiceProblem(
                "Can congruent {$tile} tiles cover a flat surface by themselves with no gaps or overlaps?",
                ['Yes', 'No', 'Only if the tiles overlap'],
                $answer,
                ['Focus on what happens where several corners meet.', $angle > 0 ? "Check whether whole copies of {$angle}° fill 360°." : 'Curved edges leave spaces when congruent circles touch.'],
                $tessellates
                    ? "Yes. {$copies} corners of {$angle}° fit exactly around a point."
                    : "No. Congruent {$tile} tiles leave gaps when used alone."
            );
        }

        if ($variant === 1) {
            $validIndex = $this->number(0, 2, 222, $seed);
            $answer = $tiles[$validIndex][0];

            return $this->choiceProblem(
                'Which regular tile can meet copies of itself at a point so the corner angles total exactly 360°?',
                [$answer, 'Regular pentagon', 'Circle'],
                $answer,
                ['A full turn around one point is 360°.', 'The valid tile needs a whole number of equal corner angles to fill that turn.'],
                "A {$answer} has angles that fit a whole-number of times into 360°."
            );
        }

        if ($variant === 2) {
            $validIndex = $this->number(0, 2, 223, $seed);
            [$validTile, $validAngle, $validCopies] = $tiles[$validIndex];
            $answer = $validCopies;

            return $this->numberProblem(
                "A tessellation uses only {$validTile} tiles. Each corner is {$validAngle}°. How many tile corners meet to make 360° around one point?",
                $answer,
                ['Angles around a point total 360°.', "Divide 360 by {$validAngle}."],
                "360 ÷ {$validAngle} = {$answer} tile corners."
            );
        }

        if ($variant === 3) {
            $triangles = $this->number(1, 3, 224, $seed);
            $squares = $this->number(1, 2, 225, $seed);
            $usedAngle = ($triangles * 60) + ($squares * 90);
            if ($usedAngle >= 360) {
                $triangles = 1;
                $squares = 2;
                $usedAngle = 240;
            }
            $answer = 360 - $usedAngle;

            return $this->numberProblem(
                "At one point in a mixed tessellation, {$triangles} triangle corner" . ($triangles === 1 ? '' : 's') . " of 60° and {$squares} square corner" . ($squares === 1 ? '' : 's') . ' of 90° already meet. How many more degrees must be filled so there is no gap?',
                $answer,
                ['Add the angles already placed.', 'Subtract that sum from 360°.'],
                "The placed angles total {$usedAngle}°, leaving {$answer}°."
            );
        }

        return $this->choiceProblem(
            'Why can regular pentagons not make a tessellation by themselves?',
            ['Whole copies of their 108° corners cannot fill 360° exactly', 'They have straight sides', 'They have five vertices'],
            'Whole copies of their 108° corners cannot fill 360° exactly',
            ['A shape may have straight sides and still tessellate.', 'Test multiples of 108° against 360°.'],
            'Three pentagon corners total 324° and four total 432°, so a gap or overlap remains.'
        );
    }

    private function numberSenseProblem(
        string $kind,
        int $difficulty,
        ?int $seed,
        array $options
    ): array {
        $limit = (int) ($options['limit'] ?? 100);

        return match ($kind) {
            'whole-numbers' => $this->wholeNumberProblem($limit, $difficulty, $seed),
            'ordinals' => $this->ordinalProblem($limit, $difficulty, $seed),
            'odd-even' => $this->oddEvenProblem($limit, $difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown number-sense practice kind.'),
        };
    }

    private function measurementProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'length-nonstandard' => $this->nonStandardLength($difficulty, $seed),
            'length-metric' => $this->metricLength($difficulty, $seed),
            'mass' => $this->metricConversion('kg', 'g', 1000, $difficulty, $seed),
            'capacity' => $this->metricConversion('L', 'mL', 1000, $difficulty, $seed),
            'unit-conversion' => $this->mixedUnitConversion($difficulty, $seed),
            'volume-capacity' => $this->metricConversion('L', 'cm³', 1000, $difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown measurement practice kind.'),
        };
    }

    private function dataProblem(string $kind, int $difficulty, ?int $seed, array $options): array
    {
        return match ($kind) {
            'pictograph' => $this->pictographProblem($difficulty, $seed, (int) ($options['scale'] ?? 1)),
            'bar-graph' => $this->comparisonGraphProblem('bar graph', $difficulty, $seed),
            'likelihood' => $this->likelihoodProblem($seed),
            'line-graph' => $this->comparisonGraphProblem('line graph', $difficulty, $seed),
            'double-graph' => $this->doubleGraphProblem($difficulty, $seed),
            'theoretical-probability' => $this->theoreticalProbability($difficulty, $seed),
            'pie-graph' => $this->pieGraphProblem($seed),
            default => throw new InvalidArgumentException('Unknown data practice kind.'),
        };
    }

    private function patternProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'repeating' => $this->repeatingPattern($difficulty, $seed),
            'increasing-decreasing' => $this->numericPattern($difficulty, $seed, false),
            'combined' => $this->combinedPatternProblem($difficulty, $seed),
            'simple-rule' => $this->numericPattern($difficulty, $seed, true),
            default => throw new InvalidArgumentException('Unknown pattern practice kind.'),
        };
    }

    private function fractionProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'halves-quarters' => $this->halvesAndQuartersProblem($difficulty, $seed),
            'unit-similar' => $this->unitFractions($difficulty, $seed),
            'similar-add-sub' => $this->similarFractionOperation($difficulty, $seed),
            'compare-equivalent' => $this->equivalentFractions($difficulty, $seed),
            'dissimilar-add-sub' => $this->dissimilarFractionOperation($difficulty, $seed),
            'multiply' => $this->multiplyFractions($difficulty, $seed),
            'divide' => $this->divideFractions($difficulty, $seed),
            'mixed-operations' => $this->mixedFractionOperation($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown fraction practice kind.'),
        };
    }

    private function halvesAndQuartersProblem(int $difficulty, ?int $seed): array
    {
        $totalSections = [4, 8, 12, 16][$this->number(0, min(3, (int) ceil($difficulty / 2)), 148, $seed)];
        $fractions = [[1, 2], [1, 4], [3, 4]];
        [$numerator, $denominator] = $fractions[$this->number(0, min(2, (int) floor($difficulty / 2)), 149, $seed)];
        $selected = (int) (($totalSections * $numerator) / $denominator);
        $variant = $this->number($difficulty >= 4 ? 1 : 0, $difficulty >= 4 ? 5 : 3, 209, $seed);

        if ($variant === 0) {
            $answer = (string) $selected;

            return $this->choiceProblem(
                "A mosaic has {$totalSections} equal tiles. How many tiles must be colored to show {$numerator}/{$denominator} of the mosaic?",
                [$answer, (string) max(0, $selected - 1), (string) min($totalSections, $selected + $denominator)],
                $answer,
                ['Divide the whole into the number of groups named by the denominator.', 'Take the number of groups named by the numerator.'],
                "{$numerator}/{$denominator} of {$totalSections} tiles is {$selected} tiles."
            );
        }

        if ($variant === 1) {
            $remaining = $totalSections - $selected;
            $answer = $this->fractionLabel($remaining, $totalSections);

            return $this->choiceProblem(
                "A tray is divided into {$totalSections} equal spaces. {$selected} spaces are covered. What fraction of the tray remains uncovered?",
                $this->fractionOptions($remaining, $totalSections),
                $answer,
                ['Subtract the covered spaces from all spaces.', 'Write remaining spaces over total spaces, then simplify.'],
                "{$totalSections} − {$selected} = {$remaining}; {$remaining}/{$totalSections} simplifies to {$answer}."
            );
        }

        if ($variant === 2) {
            $firstPart = (int) ($totalSections / 4);
            $secondPart = (int) ($totalSections / 2);
            $answer = (string) ($secondPart - $firstPart);

            return $this->choiceProblem(
                "One model shows one quarter of {$totalSections} counters. Another shows one half of the same {$totalSections} counters. How many more counters are in the half model?",
                [$answer, (string) $secondPart, (string) $totalSections],
                $answer,
                ['Find one quarter and one half of the same total.', 'Subtract the smaller part from the larger part.'],
                "One quarter is {$firstPart} and one half is {$secondPart}; the difference is {$answer}."
            );
        }

        if ($variant === 4) {
            $alreadyColored = (int) ($totalSections / 4);
            $targetColored = (int) (($totalSections * 3) / 4);
            $answer = (string) ($targetColored - $alreadyColored);

            return $this->choiceProblem(
                "A class wants to color 3/4 of a {$totalSections}-tile mosaic. They have already colored 1/4 of it. How many more tiles must be colored?",
                [$answer, (string) $alreadyColored, (string) $targetColored, (string) $totalSections],
                $answer,
                ['Find how many tiles make the three-quarter target.', 'Subtract the one-quarter part already finished.'],
                "The target is {$targetColored} tiles and {$alreadyColored} are done, so " . ($targetColored - $alreadyColored) . " more tiles are needed."
            );
        }

        if ($variant === 5) {
            $half = (int) ($totalSections / 2);
            $quarter = (int) ($totalSections / 4);
            $answer = (string) ($totalSections - $half - $quarter);

            return $this->choiceProblem(
                "Of {$totalSections} counters, one half are in Box A and one quarter are in Box B. How many counters are left for Box C?",
                [$answer, (string) $half, (string) ($half + $quarter), (string) $totalSections],
                $answer,
                ['Find one half and one quarter of the same total.', 'Subtract both known parts from all the counters.'],
                "Box A has {$half} and Box B has {$quarter}; {$totalSections} − {$half} − {$quarter} = {$answer}."
            );
        }

        $quarterCount = $this->number(1, 3, 150, $seed);
        $answer = $this->fractionLabel($quarterCount, 4);

        return $this->choiceProblem(
            "A paper strip is folded into 4 equal parts and {$quarterCount} part" . ($quarterCount === 1 ? ' is' : 's are') . ' shaded. Which simplified fraction describes the shaded strip?',
            $this->fractionOptions($quarterCount, 4),
            $answer,
            ['The denominator is the total number of equal parts.', 'The numerator is the number of shaded parts; simplify if possible.'],
            "{$quarterCount} of 4 equal parts is {$answer}."
        );
    }

    private function moneyProblem(string $kind, int $difficulty, ?int $seed, int $limit): array
    {
        $maximum = min($limit, max(20, (int) ceil($limit * ($difficulty + 1) / 6)));

        if ($kind === 'value') {
            $denominations = $difficulty >= 4 ? [5, 10, 20] : [1, 5, 10, 20];
            $firstIndex = $this->number(0, count($denominations) - 1, 56, $seed);
            $secondIndex = ($firstIndex + $this->number(1, count($denominations) - 1, 57, $seed)) % count($denominations);
            $firstCoin = $denominations[$firstIndex];
            $secondCoin = $denominations[$secondIndex];
            $maximumFirstCount = max(1, min(5, (int) floor(($limit - $secondCoin) / max(1, $firstCoin))));
            $firstCount = $this->number(1, $maximumFirstCount, 58, $seed);
            $remainingLimit = $limit - ($firstCoin * $firstCount);
            $secondCount = $this->number(1, max(1, min(5, (int) floor($remainingLimit / $secondCoin))), 59, $seed);
            $firstValue = $firstCoin * $firstCount;
            $secondValue = $secondCoin * $secondCount;
            $answer = $firstValue + $secondValue;
            $prompt = $this->promptVariant([
                "A purse has {$firstCount} ₱{$firstCoin} coin" . ($firstCount === 1 ? '' : 's') . " and {$secondCount} ₱{$secondCoin} coin" . ($secondCount === 1 ? '' : 's') . '. What is the total value?',
                "Count this mixed set of money: {$firstCount} coins worth ₱{$firstCoin} each, plus {$secondCount} coins worth ₱{$secondCoin} each. How many pesos is that?",
                "A learner combines {$firstCount} × ₱{$firstCoin} with {$secondCount} × ₱{$secondCoin}. What amount can be paid exactly?",
            ], $seed, 231);

            return $this->numberProblem(
                $prompt,
                $answer,
                ['Find the value of each denomination group separately.', 'Add the two group values.'],
                "{$firstCount} × ₱{$firstCoin} + {$secondCount} × ₱{$secondCoin} = ₱{$answer}."
            );
        }

        $minimumAmount = $difficulty >= 4 ? max(10, (int) floor($maximum / 4)) : 5;
        $first = $this->number($minimumAmount, max($minimumAmount, (int) floor($maximum * 0.65)), 54, $seed);
        $second = $this->number(5, max(5, $maximum - $first), 55, $seed);

        if ($difficulty >= 4 && $limit >= 1000) {
            $third = $this->number(5, max(5, min(200, $maximum - $first - $second)), 60, $seed);
            if ($first + $second + $third >= $limit) {
                $third = 5;
                $second = max(5, $limit - $first - $third - 20);
            }
            $budget = min($limit, $first + $second + $third + $this->number(20, 100, 61, $seed));
            $answer = $budget - $first - $second - $third;

            return $this->numberProblem(
                "A class has ₱{$budget}. It buys materials for ₱{$first}, ₱{$second}, and ₱{$third}. How much money remains?",
                $answer,
                ['Add the three costs.', 'Subtract the combined cost from the budget.'],
                "₱{$budget} − (₱{$first} + ₱{$second} + ₱{$third}) = ₱{$answer}."
            );
        }

        $addition = $this->number(0, 1, 176, $seed) === 1;
        if (!$addition && $second > $first) {
            [$first, $second] = [$second, $first];
        }
        $answer = $addition ? $first + $second : $first - $second;
        $prompt = $addition
            ? $this->promptVariant([
                "A learner buys items costing ₱{$first} and ₱{$second}. How much is the total cost?",
                "Two school supplies cost ₱{$first} and ₱{$second}. What amount is needed to buy both?",
                "Complete the peso total: ₱{$first} + ₱{$second} = ₱__.",
            ], $seed, 232)
            : $this->promptVariant([
                "A learner has ₱{$first} and spends ₱{$second}. How much money remains?",
                "An item costs ₱{$second}. If you pay from ₱{$first}, how many pesos are left?",
                "Complete the change calculation: ₱{$first} − ₱{$second} = ₱__.",
            ], $seed, 233);

        return $this->numberProblem(
            $prompt,
            $answer,
            [$addition ? 'Add the two peso amounts.' : 'Subtract the amount spent.', 'Line up equal place values before calculating.'],
            "₱{$first} " . ($addition ? '+' : '−') . " ₱{$second} = ₱{$answer}."
        );
    }

    private function timeProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'clock-calendar' => $this->clockAndCalendarProblem($difficulty, $seed),
            'elapsed' => $this->elapsedTime($difficulty, $seed),
            'time-systems' => $this->timeSystemConversion($seed),
            'time-zones' => $this->timeZoneProblem($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown time practice kind.'),
        };
    }

    private function clockAndCalendarProblem(int $difficulty, ?int $seed): array
    {
        $variant = $this->number(0, 5, 210, $seed);

        if ($variant === 0) {
            $hour = $this->number(1, 11, 128, $seed);
            $minutes = [15, 30, 45][$this->number(0, min(2, (int) ceil($difficulty / 2)), 129, $seed)];
            if ($minutes === 45) {
                $spokenHour = $hour + 1;
                $words = "quarter to {$spokenHour}";
            } elseif ($minutes === 30) {
                $words = "half past {$hour}";
            } else {
                $words = "quarter past {$hour}";
            }
            $answer = $this->clockLabel(($hour * 60) + $minutes);

            return $this->choiceProblem(
                "The minute hand and hour hand show {$words}. Which digital time matches?",
                [$answer, $this->clockLabel(($hour * 60) + (($minutes + 15) % 60)), $this->clockLabel((($hour + 1) * 60) + $minutes)],
                $answer,
                ['A quarter hour is 15 minutes and a half hour is 30 minutes.', 'For “quarter to,” use 45 minutes in the hour before the named hour.'],
                "{$words} is {$answer}."
            );
        }

        if ($variant === 1) {
            $startHour = $this->number(1, 9, 130, $seed);
            $startMinutes = [0, 15, 30, 45][$this->number(0, min(3, $difficulty), 131, $seed)];
            $duration = [15, 30, 45, 60][$this->number(0, min(3, $difficulty), 132, $seed)];
            $startTotal = ($startHour * 60) + $startMinutes;
            $answer = $this->clockLabel($startTotal + $duration);
            $start = $this->clockLabel($startTotal);

            return $this->choiceProblem(
                "A reading session begins at {$start} and lasts {$duration} minutes. Which clock time shows when it ends?",
                [$answer, $this->clockLabel($startTotal + max(0, $duration - 15)), $this->clockLabel($startTotal + $duration + 15)],
                $answer,
                ['Move forward in 15-minute jumps.', 'Regroup 60 minutes as one hour when needed.'],
                "Moving {$duration} minutes forward from {$start} reaches {$answer}."
            );
        }

        if ($variant === 2) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $startIndex = $this->number(0, 6, 133, $seed);
            $shift = $this->number(2, 2 + $difficulty, 134, $seed);
            $answer = $days[($startIndex + $shift) % 7];

            return $this->choiceProblem(
                "A class plants seeds on {$days[$startIndex]} and checks them {$shift} days later. On which day will the check happen?",
                [$answer, $days[($startIndex + $shift - 1) % 7], $days[($startIndex + $shift + 1) % 7]],
                $answer,
                ['The day after the planting day is one day later.', 'Count forward one day at a time and wrap after Sunday.'],
                "Counting {$shift} days after {$days[$startIndex]} lands on {$answer}."
            );
        }

        if ($variant === 3) {
            $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            $startIndex = $this->number(0, 11, 135, $seed);
            $shift = $this->number(2, min(5, 2 + $difficulty), 136, $seed);
            $answer = $months[($startIndex + $shift) % 12];

            return $this->choiceProblem(
                "A project begins in {$months[$startIndex]} and its exhibit opens {$shift} months later. In which month does it open?",
                [$answer, $months[($startIndex + $shift - 1) % 12], $months[($startIndex + $shift + 1) % 12]],
                $answer,
                ['The next month is one month later.', 'Continue through December to January if the count crosses the year.'],
                "{$shift} months after {$months[$startIndex]} is {$answer}."
            );
        }

        if ($variant === 4) {
            $weeks = $this->number(2, 2 + $difficulty, 137, $seed);
            $extraDays = $this->number(1, 5, 138, $seed);
            $answer = ($weeks * 7) + $extraDays;

            return $this->numberProblem(
                "A plant study lasts {$weeks} full weeks and {$extraDays} extra days. How many days does it last altogether?",
                $answer,
                ['Convert each full week to 7 days.', 'Add the extra days after converting the weeks.'],
                "{$weeks} × 7 + {$extraDays} = {$answer} days."
            );
        }

        $startHour = $this->number(1, 9, 139, $seed);
        $startMinutes = [0, 15, 30][$this->number(0, 2, 140, $seed)];
        $duration = [30, 45, 60, 75, 90][$this->number(0, min(4, $difficulty), 141, $seed)];
        $start = $this->clockLabel(($startHour * 60) + $startMinutes);
        $end = $this->clockLabel(($startHour * 60) + $startMinutes + $duration);

        return $this->numberProblem(
            "A game starts at {$start} and finishes at {$end}. How many minutes pass?",
            $duration,
            ['Count to the next full or half hour first.', 'Add the time chunks between the start and finish.'],
            "The elapsed time from {$start} to {$end} is {$duration} minutes."
        );
    }

    private function transformationProblem(string $kind, int $difficulty, ?int $seed): array
    {
        if ($kind === 'combined') {
            $kinds = ['translation', 'reflection', 'rotation'];
            $kind = $kinds[$this->number(0, 2, 58, $seed)];
        }

        return match ($kind) {
            'turns' => $this->turnProblem($difficulty, $seed),
            'translation' => $this->twoDirectionTranslationProblem($difficulty, $seed),
            'translation-one-direction' => $this->translationProblem($difficulty, $seed),
            'translation-two-direction' => $this->twoDirectionTranslationProblem($difficulty, $seed),
            'reflection' => $this->reflectionProblem($difficulty, $seed),
            'rotation' => $this->rotationProblem($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown transformation practice kind.'),
        };
    }

    private function arithmeticProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'multiplication-properties' => $this->multiplicationProperty($difficulty, $seed),
            'multi-digit-multiplication' => $this->multiDigitMultiplication($difficulty, $seed),
            'estimate-products' => $this->estimateProduct($difficulty, $seed),
            'multi-digit-division' => $this->multiDigitDivision($difficulty, $seed),
            'estimate-quotients' => $this->estimateQuotient($difficulty, $seed),
            'large-add-subtract' => $this->largeAddSubtract($difficulty, $seed),
            'order-operations' => $this->orderOfOperations($difficulty, $seed),
            'gmdas' => $this->orderOfOperations($difficulty, $seed, true),
            'number-sentences' => $this->numberSentence($difficulty, $seed),
            'gmdas-fractions-decimals' => $this->fractionDecimalGmdas($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown arithmetic practice kind.'),
        };
    }

    private function decimalProblem(string $kind, int $difficulty, ?int $seed, int $places): array
    {
        return match ($kind) {
            'place-value' => $this->decimalPlaceValue($places, $difficulty, $seed),
            'fraction-relationship' => $this->decimalFractionConversion($places, $seed),
            'compare-convert' => $this->decimalCompareConvert($places, $seed),
            'add-subtract' => $this->decimalAddSubtract($places, $difficulty, $seed),
            'multiply' => $this->decimalMultiplication($places, $difficulty, $seed),
            'divide' => $this->decimalDivision($difficulty, $seed),
            'four-operations' => $this->fourDecimalOperations($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown decimal practice kind.'),
        };
    }

    private function numberTheoryProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'factors-multiples' => $this->factorMultipleProblem($difficulty, $seed),
            'divisibility' => $this->divisibilityProblem($difficulty, $seed),
            'prime-composite' => $this->primeCompositeProblem($difficulty, $seed),
            'gcf-lcm' => $this->gcfLcmProblem($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown number-theory practice kind.'),
        };
    }

    private function ratioProblem(string $kind, int $difficulty, ?int $seed): array
    {
        return match ($kind) {
            'ratios', 'proportions' => $this->ratios($difficulty, $seed),
            'percentages' => $this->percentages($difficulty, $seed),
            default => throw new InvalidArgumentException('Unknown ratio practice kind.'),
        };
    }

    private function exponentProblem(int $difficulty, ?int $seed): array
    {
        $base = $this->number(2, 3 + $difficulty, 59, $seed);
        $exponent = $this->number(2, min(4, 2 + (int) ceil($difficulty / 2)), 60, $seed);
        $power = $base ** $exponent;
        $addend = $this->number(1, min($power - 1, 4 + $difficulty), 61, $seed);
        $factor = $this->number(2, 3 + (int) ceil($difficulty / 2), 62, $seed);
        $variantMinimum = $difficulty >= 5 ? 2 : 0;
        $variant = $this->number($variantMinimum, min(4, 1 + $difficulty), 274, $seed);
        [$expression, $answer, $firstStep, $remainingStep] = match ($variant) {
            0 => [
                "{$base}^{$exponent} + {$addend}",
                $power + $addend,
                "{$base}^{$exponent} = {$power}",
                "{$power} + {$addend} = " . ($power + $addend),
            ],
            1 => [
                "{$base}^{$exponent} − {$addend}",
                $power - $addend,
                "{$base}^{$exponent} = {$power}",
                "{$power} − {$addend} = " . ($power - $addend),
            ],
            2 => [
                "{$base}^{$exponent} + {$addend} × {$factor}",
                $power + ($addend * $factor),
                "{$base}^{$exponent} = {$power} and {$addend} × {$factor} = " . ($addend * $factor),
                "{$power} + " . ($addend * $factor) . ' = ' . ($power + ($addend * $factor)),
            ],
            3 => [
                "({$base}^{$exponent} − {$addend}) × {$factor}",
                ($power - $addend) * $factor,
                "{$base}^{$exponent} = {$power}",
                "({$power} − {$addend}) × {$factor} = " . (($power - $addend) * $factor),
            ],
            default => [
                "{$base}^{$exponent} ÷ {$base} + {$addend}",
                (int) ($power / $base) + $addend,
                "{$base}^{$exponent} = {$power}, then {$power} ÷ {$base} = " . (int) ($power / $base),
                (int) ($power / $base) . " + {$addend} = " . ((int) ($power / $base) + $addend),
            ],
        };
        $prompt = $this->promptVariant([
            "Apply GEMDAS: {$expression} = ?",
            "Evaluate the exponential expression {$expression} in the correct order.",
            "A learner wants to calculate {$expression}. What result should the learner get after applying GEMDAS?",
        ], $seed, 275);

        return $this->numberProblem(
            $prompt,
            $answer,
            ["Evaluate the exponent {$base}^{$exponent} first.", 'Then follow grouping symbols and multiplication or division before addition or subtraction.'],
            "First, {$firstStep}. Then {$remainingStep}."
        );
    }

    private function circleProblem(string $kind, int $difficulty, ?int $seed): array
    {
        $radius = $this->number(2, 3 + $difficulty, 62, $seed);
        $diameter = $radius * 2;

        if ($kind === 'parts-circumference') {
            $answer = $this->formatNumber(3.14 * $diameter);
            $prompt = $this->promptVariant([
                "Use π = 3.14. What is the circumference of a circle with diameter {$diameter} cm?",
                "A circular wheel has radius {$radius} cm. Using π = 3.14, how far around the wheel is it?",
                "Calculate C = πd when d = {$diameter} cm and π = 3.14.",
            ], $seed, 242);

            return $this->numberProblem(
                $prompt,
                $answer,
                ['Use C = πd.', "Multiply 3.14 × {$diameter}."],
                "C = 3.14 × {$diameter} = {$answer} cm."
            );
        }

        if ($kind === 'area') {
            $answer = $this->formatNumber(3.14 * $radius * $radius);
            $prompt = $this->promptVariant([
                "Use π = 3.14. What is the area of a circle with radius {$radius} cm?",
                "A circular garden has radius {$radius} cm. Using π = 3.14, how many square centimeters does it cover?",
                "Evaluate A = πr² for r = {$radius} cm and π = 3.14.",
            ], $seed, 243);

            return $this->numberProblem(
                $prompt,
                $answer,
                ['Use A = πr².', "Square {$radius}, then multiply by 3.14."],
                "A = 3.14 × {$radius}² = {$answer} cm²."
            );
        }

        if ($kind === 'composite-area') {
            return $this->compositeCircleArea($radius, $seed);
        }

        throw new InvalidArgumentException('Unknown circle practice kind.');
    }

    private function rectangleArea(int $difficulty, ?int $seed): array
    {
        $length = $this->number(2, 5 + ($difficulty * 2), 63, $seed);
        $width = $this->number(2, 4 + $difficulty, 64, $seed);
        $area = $length * $width;
        $variantMinimum = $difficulty >= 5 ? 4 : 0;
        $variant = $this->number($variantMinimum, $difficulty >= 4 ? 5 : 3, 219, $seed);

        if ($variant === 4) {
            return $this->numberProblem(
                "A rectangular tile design covers {$area} square centimeters and has {$length} rows. How many unit squares are in each row?",
                $width,
                ['Area equals rows × squares per row.', "Use {$area} ÷ {$length}."],
                "{$area} ÷ {$length} = {$width} unit squares per row."
            );
        }

        if ($variant === 5) {
            $secondWidth = $this->number(2, 4 + $difficulty, 65, $seed);
            $answer = $area + ($length * $secondWidth);

            return $this->numberProblem(
                "Two non-overlapping rectangles share the same length of {$length} cm. Their widths are {$width} cm and {$secondWidth} cm. What is their combined area?",
                $answer,
                ['Find the area of each rectangle.', 'Add the two non-overlapping areas.'],
                "{$length} × {$width} + {$length} × {$secondWidth} = {$answer} cm²."
            );
        }

        $answer = $area;
        $prompt = match ($variant) {
            0 => "A rectangle is {$length} cm long and {$width} cm wide. What is its area in square centimeters?",
            1 => "A rectangular tile grid has {$length} columns and {$width} rows of unit squares. How many square units does it cover?",
            2 => "A poster measures {$length} cm by {$width} cm. Calculate the surface it covers in cm².",
            default => "Use A = l × w to find the area when l = {$length} cm and w = {$width} cm.",
        };

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Area counts the square units inside the rectangle.', "Multiply length × width: {$length} × {$width}."],
            "{$length} × {$width} = {$answer} square centimeters."
        );
    }

    private function shapeProperties(int $difficulty, ?int $seed): array
    {
        $variant = $this->number(0, 4, 207, $seed);
        $sideA = $this->number(3, 6 + $difficulty, 226, $seed);
        $sideB = $this->number(3, 6 + $difficulty, 227, $seed);
        if ($sideB === $sideA) {
            $sideB++;
        }

        if ($variant === 0) {
            $properties = [
                ['Square', 'four equal sides and four right angles'],
                ['Rectangle', 'four right angles, with one pair of opposite sides longer than the other pair'],
                ['Trapezoid', 'exactly one pair of parallel sides'],
                ['Parallelogram', 'two pairs of parallel sides without requiring right angles'],
            ];
            [$answer, $clue] = $properties[$this->number(0, count($properties) - 1, 228, $seed)];

            return $this->choiceProblem(
                "A mystery quadrilateral has {$clue}. Which classification is most precise?",
                ['Square', 'Rectangle', 'Trapezoid', 'Parallelogram'],
                $answer,
                ['Use both the side and angle clues.', 'Choose the most specific class supported by every clue.'],
                "Those properties classify the quadrilateral as a {$answer}."
            );
        }

        if ($variant === 1) {
            $makeSquare = $this->number(0, 1, 229, $seed) === 1;
            $lengths = $makeSquare
                ? [$sideA, $sideA, $sideA, $sideA]
                : [$sideA, $sideB, $sideA, $sideB];
            $answer = $makeSquare ? 'Square' : 'Rectangle';

            return $this->choiceProblem(
                'A quadrilateral has four right angles and consecutive side lengths '
                    . implode(', ', $lengths) . ' cm. Which name is most precise?',
                ['Square', 'Rectangle', 'Trapezoid'],
                $answer,
                ['Four right angles narrow the choices.', 'Then decide whether all four side lengths match.'],
                "The side and angle information identifies a {$answer}."
            );
        }

        if ($variant === 2) {
            $triangleType = ['Equilateral', 'Isosceles', 'Scalene'][$this->number(0, 2, 230, $seed)];
            $lengths = match ($triangleType) {
                'Equilateral' => [$sideA, $sideA, $sideA],
                'Isosceles' => [$sideA, $sideA, $sideA + 1],
                default => [$sideA, $sideA + 1, $sideA + 2],
            };

            return $this->choiceProblem(
                'A triangle has side lengths ' . implode(', ', $lengths) . ' cm. How is it classified by its sides?',
                ['Equilateral', 'Isosceles', 'Scalene'],
                $triangleType,
                ['Compare all three measurements.', 'Three equal means equilateral, two equal means isosceles, and none equal means scalene.'],
                "The measurements make the triangle {$triangleType}."
            );
        }

        if ($variant === 3) {
            $correct = 'Every square is also a rectangle because it has four right angles.';

            return $this->choiceProblem(
                'Which classification statement is mathematically correct?',
                [$correct, 'Every rectangle is a square because it has four sides.', 'Every trapezoid has two pairs of parallel sides.'],
                $correct,
                ['A broad class can contain a more specific class.', 'Check every required property, not only the number of sides.'],
                'A square satisfies every defining property of a rectangle, with the extra rule that all sides are equal.'
            );
        }

        return $this->choiceProblem(
            'Which pair of figures must each have two pairs of parallel opposite sides?',
            ['Rectangle and parallelogram', 'Triangle and trapezoid', 'Trapezoid and kite'],
            'Rectangle and parallelogram',
            ['Check both pairs of opposite sides for each figure.', 'A trapezoid in this curriculum has only one pair of parallel sides.'],
            'Rectangles and parallelograms both have two pairs of parallel opposite sides.'
        );
    }

    private function compositePerimeter(int $difficulty, ?int $seed): array
    {
        $length = $this->number(6, 9 + ($difficulty * 2), 66, $seed);
        $width = $this->number(4, 6 + $difficulty, 67, $seed);
        $attachment = $this->number(2, max(2, min($width, 3 + $difficulty)), 68, $seed);
        $rectanglePerimeter = 2 * ($length + $width);
        $squarePerimeter = 4 * $attachment;
        $answer = $rectanglePerimeter + $squarePerimeter - (2 * $attachment);
        $prompt = $this->promptVariant([
            "A {$length} cm by {$width} cm rectangle and a square with side {$attachment} cm are joined along one full {$attachment} cm edge. What is the perimeter of the combined figure?",
            "A square tab of side {$attachment} cm is attached to the outside of a {$length} cm × {$width} cm card. The shared {$attachment} cm edge becomes internal. Find the new outside perimeter.",
            "Two figures are joined: a {$length}-by-{$width} cm rectangle and an {$attachment}-by-{$attachment} cm square. They share one side of length {$attachment} cm. How long is the outer boundary?",
        ], $seed, 244);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Find each separate perimeter first.', 'The shared edge was counted once on each shape, so subtract it twice.'],
            "{$rectanglePerimeter} + {$squarePerimeter} − " . (2 * $attachment) . " = {$answer} cm."
        );
    }

    private function symmetryProblem(int $difficulty, ?int $seed): array
    {
        $shapes = [
            ['Square', 4],
            ['Rectangle that is not a square', 2],
            ['Equilateral triangle', 3],
            ['Isosceles triangle that is not equilateral', 1],
            ['Regular pentagon', 5],
            ['Regular hexagon', 6],
        ];
        $shapeIndex = $this->number(0, count($shapes) - 1, 70, $seed);
        [$shape, $lineCount] = $shapes[$shapeIndex];
        $variant = $this->number($difficulty >= 4 ? 1 : 0, 3, 236, $seed);

        if ($variant === 1) {
            $otherIndex = ($shapeIndex + $this->number(1, count($shapes) - 1, 237, $seed)) % count($shapes);
            [$otherShape, $otherCount] = $shapes[$otherIndex];
            $answer = abs($lineCount - $otherCount);

            return $this->numberProblem(
                "How many more lines of symmetry does the figure with more symmetry have: a {$shape} or a {$otherShape}?",
                $answer,
                ['Find or recall the symmetry-line count for each shape.', 'Subtract the smaller count from the larger.'],
                "The counts are {$lineCount} and {$otherCount}, so the difference is {$answer}."
            );
        }

        if ($variant === 2) {
            $otherIndex = ($shapeIndex + $this->number(1, count($shapes) - 1, 238, $seed)) % count($shapes);
            [$otherShape, $otherCount] = $shapes[$otherIndex];
            if ($otherCount === $lineCount) {
                $otherIndex = ($otherIndex + 1) % count($shapes);
                [$otherShape, $otherCount] = $shapes[$otherIndex];
            }
            $answer = $lineCount > $otherCount ? $shape : $otherShape;

            return $this->choiceProblem(
                "Which has more lines of symmetry: a {$shape} or a {$otherShape}?",
                [$shape, $otherShape, 'They have the same number'],
                $answer,
                ['Imagine every fold that makes matching halves.', 'Count vertical, horizontal, and diagonal folds when they work.'],
                "The {$shape} has {$lineCount}; the {$otherShape} has {$otherCount}. Therefore, {$answer} has more."
            );
        }

        if ($variant === 3) {
            $colors = ['red', 'blue', 'green', 'gold'];
            $color = $colors[$this->number(0, count($colors) - 1, 239, $seed)];
            $answer = (string) $lineCount;

            return $this->choiceProblem(
                "A {$color} paper {$shape} must be folded into matching mirror halves in every possible way. How many different valid fold lines are there?",
                [$answer, (string) max(0, $lineCount - 1), (string) ($lineCount + 1)],
                $answer,
                ['A valid fold makes both halves match exactly.', 'Rotate the imagined fold and count each distinct direction once.'],
                "A {$shape} has {$lineCount} valid symmetry line" . ($lineCount === 1 ? '.' : 's.')
            );
        }

        return $this->numberProblem(
            "How many lines of symmetry does a {$shape} have?",
            $lineCount,
            ['A symmetry line divides a figure into matching mirror halves.', 'Consider vertical, horizontal, and diagonal folds.'],
            "A {$shape} has {$lineCount} line" . ($lineCount === 1 ? '' : 's') . ' of symmetry.'
        );
    }

    private function polygonArea(int $difficulty, ?int $seed): array
    {
        $variant = $this->number(0, 2, 71, $seed);
        $base = $this->number(4, 6 + ($difficulty * 2), 72, $seed);
        $height = $this->number(2, 4 + $difficulty, 73, $seed);

        if ($variant === 0) {
            $answer = $base * $height;

            return $this->numberProblem(
                "A parallelogram has base {$base} cm and height {$height} cm. What is its area?",
                $answer,
                ['Use A = base × height.', "Multiply {$base} × {$height}."],
                "The area is {$base} × {$height} = {$answer} cm²."
            );
        }

        if ($variant === 1) {
            $evenBase = $base % 2 === 0 ? $base : $base + 1;
            $answer = ($evenBase * $height) / 2;

            return $this->numberProblem(
                "A triangle has base {$evenBase} cm and height {$height} cm. What is its area?",
                $answer,
                ['Use A = base × height ÷ 2.', "Multiply {$evenBase} × {$height}, then divide by 2."],
                "The area is ({$evenBase} × {$height}) ÷ 2 = {$answer} cm²."
            );
        }

        $top = $base;
        $bottom = $base + 2;
        $answer = (($top + $bottom) * $height) / 2;

        return $this->numberProblem(
            "A trapezoid has bases {$top} cm and {$bottom} cm and height {$height} cm. What is its area?",
            $answer,
            ['Add the parallel bases, multiply by the height, then divide by 2.', "Use A = ({$top} + {$bottom}) × {$height} ÷ 2."],
            "The area is ({$top} + {$bottom}) × {$height} ÷ 2 = {$answer} cm²."
        );
    }

    private function solidFigureProblem(int $difficulty, ?int $seed): array
    {
        $solids = [
            ['Cube', 6, 12, 8, 'six square faces'],
            ['Rectangular prism', 6, 12, 8, 'two rectangular bases and four rectangular side faces'],
            ['Triangular prism', 5, 9, 6, 'two triangular bases and three rectangular side faces'],
            ['Square pyramid', 5, 8, 5, 'one square base and four triangular side faces'],
            ['Triangular pyramid', 4, 6, 4, 'one triangular base and three triangular side faces'],
        ];
        $solidIndex = $this->number(0, count($solids) - 1, 231, $seed);
        [$solid, $faces, $edges, $vertices, $description] = $solids[$solidIndex];
        $variant = $this->number(0, 4, 232, $seed);

        if ($variant === 0) {
            return $this->choiceProblem(
                "A solid has {$description}. Which solid is it?",
                ['Cube', 'Rectangular prism', 'Triangular prism', 'Square pyramid', 'Triangular pyramid'],
                $solid,
                ['Identify the number and shape of the bases.', 'Then count or describe the side faces.'],
                "Those faces form a {$solid}."
            );
        }

        if ($variant === 1) {
            $copies = $this->number(2, 2 + (int) ceil($difficulty / 2), 233, $seed);
            $askVertices = $this->number(0, 1, 234, $seed) === 1;
            $perSolid = $askVertices ? $vertices : $faces;
            $feature = $askVertices ? 'vertices' : 'faces';
            $answer = (string) ($copies * $perSolid);

            return $this->choiceProblem(
                "There are {$copies} separate {$solid} models. How many {$feature} do they have altogether?",
                [$answer, (string) ($copies + $perSolid), (string) ($copies * $perSolid + 1)],
                $answer,
                ["One {$solid} has {$perSolid} {$feature}.", 'The models are separate, so multiply rather than subtract shared parts.'],
                "{$copies} × {$perSolid} = {$answer} {$feature}."
            );
        }

        if ($variant === 2) {
            $netParts = [
                'Cube' => ['6 connected squares', '4 connected squares', '2 circles and a rectangle'],
                'Rectangular prism' => ['6 connected rectangles', '4 triangles', '1 square and 4 triangles'],
                'Triangular prism' => ['2 triangles and 3 rectangles', '1 triangle and 4 rectangles', '6 squares'],
                'Square pyramid' => ['1 square and 4 triangles', '2 squares and 3 triangles', '2 triangles and 3 rectangles'],
                'Triangular pyramid' => ['4 connected triangles', '1 square and 4 triangles', '2 triangles and 3 rectangles'],
            ];
            [$answer, $wrongOne, $wrongTwo] = $netParts[$solid];

            return $this->choiceProblem(
                "Which collection of plane figures could form a net for a {$solid}?",
                [$answer, $wrongOne, $wrongTwo],
                $answer,
                ['Match each plane figure to a face of the solid.', 'The net needs every face exactly once.'],
                "A {$solid} can be unfolded into {$answer}."
            );
        }

        if ($variant === 3) {
            $otherIndex = ($solidIndex + $this->number(1, count($solids) - 1, 235, $seed)) % count($solids);
            for ($offset = 0; $offset < count($solids) && $solids[$otherIndex][3] === $vertices; $offset++) {
                $otherIndex = ($otherIndex + 1) % count($solids);
            }
            [$otherSolid, , , $otherVertices] = $solids[$otherIndex];
            $answer = abs($vertices - $otherVertices);

            return $this->numberProblem(
                "How many more vertices does the solid with more vertices have: a {$solid} or a {$otherSolid}?",
                $answer,
                ['Recall or sketch the vertices on both solids.', 'Subtract the smaller vertex count from the larger.'],
                "The vertex counts are {$vertices} and {$otherVertices}, so the difference is {$answer}."
            );
        }

        $correct = "A {$solid} has {$faces} faces, {$edges} edges, and {$vertices} vertices.";

        return $this->choiceProblem(
            "Which description of a {$solid} is correct?",
            [$correct, 'It has ' . ($faces + 2) . " faces and {$vertices} vertices.", "It has " . ($faces + 1) . ' faces and ' . ($edges - 1) . ' edges.'],
            $correct,
            ['Separate faces, edges, and vertices before counting.', 'A net is useful for counting faces; a model is useful for edges and vertices.'],
            $correct
        );
    }

    private function surfaceArea(int $difficulty, ?int $seed): array
    {
        $length = $this->number(2, 4 + $difficulty, 75, $seed);
        $width = $this->number(2, 4 + $difficulty, 76, $seed);
        $height = $this->number(2, 4 + $difficulty, 77, $seed);
        $answer = 2 * (($length * $width) + ($length * $height) + ($width * $height));
        $prompt = $this->promptVariant([
            "A rectangular prism is {$length} cm by {$width} cm by {$height} cm. What is its surface area?",
            "A closed box measures {$length} cm × {$width} cm × {$height} cm. How many square centimeters cover all six faces?",
            "Use SA = 2(lw + lh + wh) when l = {$length}, w = {$width}, and h = {$height} centimeters.",
        ], $seed, 245);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Find the areas of the three different face pairs.', 'Use 2(lw + lh + wh).'],
            "2[({$length}×{$width}) + ({$length}×{$height}) + ({$width}×{$height})] = {$answer} cm²."
        );
    }

    private function volumeWithUnitCubes(int $difficulty, ?int $seed): array
    {
        $columns = $this->number(2, 3 + $difficulty, 187, $seed);
        $rows = $this->number(2, 3 + $difficulty, 188, $seed);
        $layers = $this->number(2, 2 + $difficulty, 189, $seed);
        $answer = $columns * $rows * $layers;
        $prompt = $this->promptVariant([
            "A rectangular-prism model has {$layers} layers of unit cubes. Each layer has {$rows} rows of {$columns} cubes. How many unit cubes fill the model?",
            "Stack {$layers} identical layers, each arranged as {$rows} by {$columns} unit cubes. How many cubes are in the stack?",
            "A box model is {$columns} cubes long, {$rows} cubes wide, and {$layers} cubes high. Find its volume in unit cubes.",
        ], $seed, 246);

        return $this->numberProblem(
            $prompt,
            $answer,
            [
                "One layer contains {$rows} × {$columns} unit cubes.",
                "Multiply the cubes in one layer by {$layers} layers.",
            ],
            "{$rows} × {$columns} × {$layers} = {$answer} unit cubes."
        );
    }

    private function compositeArea(int $difficulty, ?int $seed): array
    {
        $length = $this->number(4, 7 + $difficulty, 78, $seed);
        $width = $this->number(3, 5 + $difficulty, 79, $seed);
        $triangleHeight = $this->number(2, 4 + $difficulty, 80, $seed);
        $rectangleArea = $length * $width;
        $triangleArea = ($length * $triangleHeight) / 2;
        $answer = $rectangleArea + $triangleArea;
        $prompt = $this->promptVariant([
            "A composite figure is a {$length} cm × {$width} cm rectangle plus a triangle with base {$length} cm and height {$triangleHeight} cm. What is its total area?",
            "A house-shaped figure combines a {$length} cm by {$width} cm rectangle and a triangular roof with base {$length} cm and height {$triangleHeight} cm. Find the whole area.",
            "Add the area of a {$length} × {$width} rectangle to the area of a triangle with base {$length} and height {$triangleHeight}, all in centimeters.",
        ], $seed, 247);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Find each simple area separately.', 'Add rectangle area lw and triangle area bh ÷ 2.'],
            "{$rectangleArea} + {$triangleArea} = {$answer} cm²."
        );
    }

    private function wholeNumberProblem(int $limit, int $difficulty, ?int $seed): array
    {
        $workingLimit = min($limit, max(20, (int) ceil($limit * ($difficulty + 1) / 6)));
        $variantMinimum = $difficulty >= 5 ? 3 : ($difficulty >= 4 ? 2 : 0);
        $variant = $this->number($variantMinimum, $limit >= 10000 ? 5 : 4, 81, $seed);

        if ($variant === 0) {
            $availableSteps = $limit <= 100 ? [2, 5, 10] : ($limit <= 1000 ? [10, 50, 100] : [100, 500, 1000]);
            $step = $availableSteps[$this->number(0, min(count($availableSteps) - 1, (int) ceil($difficulty / 2)), 82, $seed)];
            $largestStart = max(0, (int) floor(($workingLimit - (4 * $step)) / $step));
            $start = $this->number(0, $largestStart, 83, $seed) * $step;
            $missingIndex = $this->number(1, 3, 84, $seed);
            $terms = [];
            for ($index = 0; $index < 5; $index++) {
                $terms[] = $index === $missingIndex ? '__' : (string) ($start + ($index * $step));
            }
            $answer = $start + ($missingIndex * $step);

            return $this->numberProblem(
                'A number is missing inside this skip-counting path: ' . implode(', ', $terms) . '. What belongs in the blank?',
                $answer,
                ["Neighboring values differ by {$step}.", 'Check the value on both sides of the blank, not just one side.'],
                "The constant step is {$step}, so the missing value is {$answer}."
            );
        }

        if ($variant === 1) {
            $hundreds = $limit >= 1000 ? $this->number(1, max(1, min(9, (int) floor($workingLimit / 100))), 85, $seed) : 0;
            $tens = $this->number(1, 9, 86, $seed);
            $ones = $this->number(1, 9, 87, $seed);
            $number = ($hundreds * 100) + ($tens * 10) + $ones;
            if ($number > $workingLimit) {
                $number = max(11, $workingLimit - ($workingLimit % 10) - 1);
                $hundreds = (int) floor($number / 100);
                $tens = (int) floor(($number % 100) / 10);
                $ones = $number % 10;
            }
            $prompt = $hundreds > 0
                ? "A place-value model has {$hundreds} hundreds, {$tens} tens, and {$ones} ones. What whole number does it represent?"
                : "A bundle model has {$tens} groups of ten and {$ones} single counters. What whole number does it represent?";

            return $this->numberProblem(
                $prompt,
                $number,
                ['Convert each place-value group to its value.', 'Add the hundreds, tens, and ones contributions.'],
                "The model represents {$number}."
            );
        }

        if ($variant === 2) {
            $number = $this->number(max(3, (int) floor($workingLimit / 3)), max(3, $workingLimit - 2), 88, $seed);
            $before = $number - 1;
            $after = $number + 1;

            return $this->choiceProblem(
                "Which number is greater than {$before}, less than {$after}, and exactly one step from each?",
                [(string) $number, (string) ($number - 2), (string) ($number + 2)],
                (string) $number,
                ['A number must satisfy both inequalities.', 'Find the only whole number between the two boundaries.'],
                "{$before} < {$number} < {$after}, so {$number} satisfies every clue."
            );
        }

        if ($variant === 3) {
            $middle = $this->number(max(4, (int) floor($workingLimit / 4)), max(4, $workingLimit - 3), 89, $seed);
            $lower = $middle - $this->number(1, min(3, $middle - 1), 90, $seed);
            $higher = $middle + $this->number(1, min(3, $workingLimit - $middle), 91, $seed);
            $values = [$higher, $lower, $middle];

            return $this->choiceProblem(
                'Without putting every number in order on paper, which is the middle value: ' . implode(', ', $values) . '?',
                array_map('strval', $values),
                (string) $middle,
                ['Compare the highest place value first.', 'The middle value is greater than one choice and less than the other.'],
                "{$lower} < {$middle} < {$higher}, so {$middle} is the middle value."
            );
        }

        if ($variant === 4) {
            $number = $this->number(max(12, (int) floor($workingLimit / 3)), $workingLimit, 92, $seed);
            $ones = $number % 10;
            $tensValue = ((int) floor($number / 10) % 10) * 10;
            if ($tensValue === 0) {
                $number = $number + 10 <= $workingLimit ? $number + 10 : $number - 10;
                $ones = $number % 10;
                $tensValue = ((int) floor($number / 10) % 10) * 10;
            }
            $higherValue = $number - $tensValue - $ones;
            $known = array_values(array_filter([$higherValue, $ones], fn (int $part): bool => $part > 0));
            if ($known === []) {
                $known = [0];
            }

            return $this->numberProblem(
                "Complete the expanded form of {$number}: " . implode(' + ', $known) . ' + __.',
                $tensValue,
                ['Find the contribution from the tens digit.', 'The missing parts and the shown parts must add back to the original number.'],
                "The missing tens contribution is {$tensValue}."
            );
        }

        $roundingUnit = $limit >= 100000 ? 10000 : ($limit >= 10000 ? 1000 : 100);
        $number = $this->number($roundingUnit, $workingLimit, 93, $seed);
        $answer = (int) (round($number / $roundingUnit) * $roundingUnit);

        return $this->numberProblem(
            "A population count is {$number}. What is it rounded to the nearest {$roundingUnit}?",
            $answer,
            ["Locate the {$roundingUnit} place.", 'Use the digit immediately to its right to decide whether to round down or up.'],
            "{$number} rounds to {$answer} to the nearest {$roundingUnit}."
        );
    }

    private function ordinalProblem(int $limit, int $difficulty, ?int $seed): array
    {
        $names = ['Ari', 'Bea', 'Cleo', 'Dino', 'Ella', 'Finn', 'Gio', 'Hana', 'Iris', 'Javi', 'Kira', 'Luis'];
        $variantMaximum = $limit >= 100 && $difficulty >= 3 ? 6 : 5;
        $variant = $this->number(0, $variantMaximum, 84, $seed);
        $position = 1;

        if ($variant === 0) {
            $lineLength = min($limit, $this->number(6, min(10, $limit), 85, $seed));
            $rotation = $this->number(0, count($names) - 1, 86, $seed);
            $line = [];
            for ($index = 0; $index < $lineLength; $index++) {
                $line[] = $names[($rotation + $index) % count($names)];
            }
            $position = $this->number(2, $lineLength - 1, 87, $seed);
            $target = $line[$position - 1];
            $positionLabel = $this->ordinal($position);
            $prompt = 'Front of line → ' . implode(', ', $line)
                . ". Without numbering the names first, what place is {$target} in?";
            $hints = ['Start at the front and count each person once.', "Stop when you reach {$target}; position words use ordinal endings."];
            $explanation = "Counting from the front places {$target} in the {$positionLabel} position.";
        } elseif ($variant === 1) {
            $ahead = $this->number(1, max(1, min($limit - 1, 2 + ($difficulty * 3))), 88, $seed);
            $position = $ahead + 1;
            $positionLabel = $this->ordinal($position);
            $runner = $names[$this->number(0, count($names) - 1, 89, $seed)];
            $prompt = "There are {$ahead} runners ahead of {$runner} in a race. What is {$runner}'s place?";
            $hints = ['The first runner has nobody ahead.', "A runner with {$ahead} people ahead comes immediately after those {$ahead} people."];
            $explanation = "{$ahead} runners are ahead, so {$runner} is in {$positionLabel} place.";
        } elseif ($variant === 2) {
            $move = $this->number(1, max(1, min(4, $difficulty + 1)), 90, $seed);
            $start = $this->number($move + 2, $limit, 91, $seed);
            $position = $start - $move;
            $startLabel = $this->ordinal($start);
            $positionLabel = $this->ordinal($position);
            $runner = $names[$this->number(0, count($names) - 1, 92, $seed)];
            $prompt = "{$runner} was {$startLabel}, then passed {$move} runner" . ($move === 1 ? '' : 's') . ". What place is {$runner} now?";
            $hints = ['Passing someone moves a runner closer to first place.', "Move backward {$move} positions from {$startLabel}."];
            $explanation = "{$start} − {$move} = {$position}, so {$runner} moves to {$positionLabel} place.";
        } elseif ($variant === 3) {
            $move = $this->number(1, max(1, min(4, $difficulty + 1)), 93, $seed);
            $start = $this->number(1, max(1, $limit - $move), 94, $seed);
            $position = $start + $move;
            $startLabel = $this->ordinal($start);
            $positionLabel = $this->ordinal($position);
            $runner = $names[$this->number(0, count($names) - 1, 95, $seed)];
            $prompt = "{$runner} was {$startLabel} when {$move} runner" . ($move === 1 ? '' : 's') . " moved ahead. What is {$runner}'s new place?";
            $hints = ['When other runners move ahead, the place number increases.', "Move forward {$move} positions from {$startLabel}."];
            $explanation = "{$start} + {$move} = {$position}, so the new place is {$positionLabel}.";
        } elseif ($variant === 4) {
            $total = $this->number(max(6, (int) ceil($limit / 2)), $limit, 96, $seed);
            $fromEnd = $this->number(2, max(2, min($total - 1, 3 + ($difficulty * 2))), 97, $seed);
            $position = $total - $fromEnd + 1;
            if ($position === $fromEnd) {
                $fromEnd = $fromEnd < $total - 1 ? $fromEnd + 1 : $fromEnd - 1;
                $position = $total - $fromEnd + 1;
            }
            $fromEndLabel = $this->ordinal($fromEnd);
            $positionLabel = $this->ordinal($position);
            $book = ['atlas', 'storybook', 'science book', 'puzzle book'][$this->number(0, 3, 98, $seed)];
            $prompt = "A shelf holds {$total} books. The {$book} is {$fromEndLabel} from the right end. What is its place when counted from the left?";
            $hints = ['Both end positions count the same book.', 'Use total − position from the right + 1.'];
            $explanation = "{$total} − {$fromEnd} + 1 = {$position}, so the {$book} is {$positionLabel} from the left.";
        } elseif ($variant === 5) {
            $gap = $this->number(1, max(1, min(4, $difficulty + 1)), 99, $seed);
            $firstPosition = $this->number(1, max(1, $limit - $gap), 100, $seed);
            $position = $firstPosition + $gap;
            $firstPositionLabel = $this->ordinal($firstPosition);
            $positionLabel = $this->ordinal($position);
            $firstIndex = $this->number(0, count($names) - 1, 101, $seed);
            $firstName = $names[$firstIndex];
            $secondOffset = $this->number(1, count($names) - 1, 102, $seed);
            $secondName = $names[($firstIndex + $secondOffset) % count($names)];
            $prompt = "{$firstName} is {$firstPositionLabel} in line. {$secondName} stands {$gap} place" . ($gap === 1 ? '' : 's') . " behind {$firstName}. What is {$secondName}'s place?";
            $hints = ['“Behind” means a larger position number.', "Count {$gap} places after {$firstPositionLabel}."];
            $explanation = "{$firstPosition} + {$gap} = {$position}, so {$secondName} is {$positionLabel}.";
        } else {
            $perRow = $this->number(4, 8, 104, $seed);
            $completedRows = $this->number(2, max(2, min(10, (int) floor(($limit - 1) / $perRow))), 105, $seed);
            $inNextRow = $this->number(1, min($perRow, $limit - ($completedRows * $perRow)), 106, $seed);
            $position = ($completedRows * $perRow) + $inNextRow;
            $inNextRowLabel = $this->ordinal($inNextRow);
            $positionLabel = $this->ordinal($position);
            $prompt = "Contest entries are checked in rows of {$perRow}. After {$completedRows} full rows, the target entry is {$inNextRowLabel} in the next row. What is its overall place?";
            $hints = ['Find how many entries are in the completed rows.', 'Then add the target entry’s position in the next row.'];
            $explanation = "{$completedRows} × {$perRow} + {$inNextRow} = {$position}, so the entry is {$positionLabel} overall.";
        }

        $wrongPositions = [];
        foreach ([$position - 1, $position + 1, $position - 2, $position + 2, 1, $limit] as $candidate) {
            if ($candidate >= 1 && $candidate <= $limit && $candidate !== $position && !in_array($candidate, $wrongPositions, true)) {
                $wrongPositions[] = $candidate;
            }
            if (count($wrongPositions) === 3) {
                break;
            }
        }
        $answer = $this->ordinal($position);

        return $this->choiceProblem(
            $prompt,
            array_merge([$answer], array_map($this->ordinal(...), $wrongPositions)),
            $answer,
            $hints,
            $explanation
        );
    }

    private function oddEvenProblem(int $limit, int $difficulty, ?int $seed): array
    {
        $maximum = min($limit, max(20, (int) ceil($limit * ($difficulty + 1) / 6)));
        $first = $this->number(2, max(2, (int) floor($maximum / 2)), 112, $seed);
        $second = $this->number(1, max(1, (int) floor($maximum / 2)), 113, $seed);
        $variant = $this->number(0, 3, 235, $seed);

        if ($variant === 1) {
            $base = $first;
            $correct = $base % 2 === 0 ? 2 : 1;
            $options = $base % 2 === 0 ? ['2', '1', '3'] : ['1', '2', '4'];

            return $this->choiceProblem(
                "Which number can be added to {$base} so the total can be arranged completely in pairs?",
                $options,
                (string) $correct,
                ['A total arranged completely in pairs is even.', 'Two numbers with the same parity have an even sum.'],
                "{$base} + {$correct} is even, so no counter is left without a partner."
            );
        }

        if ($variant === 2) {
            $center = $this->number(3, max(3, $maximum - 2), 114, $seed);
            $values = [$center - 1, $center, $center + 1];
            $wantedEven = $this->number(0, 1, 115, $seed) === 1;
            $matching = array_values(array_filter($values, fn (int $value): bool => ($value % 2 === 0) === $wantedEven));
            if (count($matching) !== 1) {
                $wantedEven = !$wantedEven;
                $matching = array_values(array_filter($values, fn (int $value): bool => ($value % 2 === 0) === $wantedEven));
            }
            $answer = (string) $matching[0];
            $label = $wantedEven ? 'even' : 'odd';

            return $this->choiceProblem(
                'Three consecutive locker numbers are ' . implode(', ', $values) . ". Which is the only {$label} number?",
                array_map('strval', $values),
                $answer,
                ['Check the final digit of each number.', 'An even number makes complete pairs; an odd number leaves one.'],
                "{$answer} is the only {$label} value in the set."
            );
        }

        $value = $variant === 3 ? abs($first - $second) : $first + $second;
        $answer = $value % 2 === 0 ? 'Even' : 'Odd';
        $operation = $variant === 3 ? 'difference' : 'total';
        $symbol = $variant === 3 ? '−' : '+';
        $larger = max($first, $second);
        $smaller = min($first, $second);
        $prompt = $variant === 3
            ? "One jar has {$larger} counters and another has {$smaller}. Is the difference between the jars odd or even?"
            : "Two trays hold {$first} and {$second} counters. Is their combined total odd or even?";

        return $this->choiceProblem(
            $prompt,
            ['Even', 'Odd', 'Neither'],
            $answer,
            ["Find the {$operation} before classifying it.", 'An even number can be split into pairs with none left over.'],
            ($variant === 3 ? "{$larger} {$symbol} {$smaller}" : "{$first} {$symbol} {$second}")
                . " = {$value}, which is {$answer}."
        );
    }

    private function nonStandardLength(int $difficulty, ?int $seed): array
    {
        $first = $this->number(2, 5 + $difficulty, 86, $seed);
        $second = $this->number(2, 5 + $difficulty, 87, $seed);
        $variant = $this->number(0, 3, 236, $seed);
        if ($variant === 0) {
            $answer = $first + $second;
            $prompt = "Two ribbons measure {$first} and {$second} paper clips using the same-size clips. What is their combined length in paper clips?";
            $hint = 'Add the two measurements because the unit size is the same.';
            $explanation = "{$first} + {$second} = {$answer} paper clips.";
        } elseif ($variant === 1) {
            $longer = max($first, $second) + $this->number(2, 4, 88, $seed);
            $shorter = min($first, $second);
            $answer = $longer - $shorter;
            $prompt = "A desk is {$longer} hand spans long and a shelf is {$shorter} hand spans long, measured by the same learner. How many hand spans longer is the desk?";
            $hint = 'Subtract the shorter measurement from the longer one.';
            $explanation = "{$longer} − {$shorter} = {$answer} hand spans.";
        } elseif ($variant === 2) {
            $total = $first + $second;
            $answer = $second;
            $prompt = "Two paths total {$total} footsteps. The first path is {$first} footsteps. How long is the second path?";
            $hint = 'Subtract the known path from the combined distance.';
            $explanation = "{$total} − {$first} = {$answer} footsteps.";
        } else {
            $extra = $this->number(1, max(1, min($first, $second) - 1), 89, $seed);
            $answer = $first + $second - $extra;
            $prompt = "Two strips measure {$first} and {$second} blocks long. They overlap by {$extra} blocks when joined. What is the length of the joined strip?";
            $hint = 'Add both lengths, then remove the overlap counted twice.';
            $explanation = "{$first} + {$second} − {$extra} = {$answer} blocks.";
        }

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Confirm that every measurement uses the same non-standard unit.', $hint],
            $explanation
        );
    }

    private function metricLength(int $difficulty, ?int $seed): array
    {
        $objects = [
            ['pencil', 'centimeters', 18],
            ['notebook', 'centimeters', 28],
            ['spoon', 'centimeters', 15],
            ['classroom door', 'meters', 2],
            ['bed', 'meters', 2],
            ['whiteboard', 'meters', 3],
            ['classroom', 'meters', 8],
            ['jump rope', 'meters', 3],
        ];
        [$object, $unit, $reasonable] = $objects[$this->number(0, count($objects) - 1, 142, $seed)];
        $variant = $difficulty >= 4
            ? $this->number(2, 3, 206, $seed)
            : $this->number(0, 2, 206, $seed);

        if ($variant === 0) {
            $answer = ucfirst($unit);

            return $this->choiceProblem(
                "A learner must measure a {$object} accurately without using an awkwardly large or tiny number. Which unit is most appropriate?",
                ['Centimeters', 'Meters', 'Kilometers'],
                $answer,
                ['Estimate the object’s size before choosing a unit.', 'Use centimeters for hand-sized objects and meters for room-sized lengths.'],
                "A {$object} is sensibly measured in {$unit}."
            );
        }

        if ($variant === 1) {
            $answer = "{$reasonable} {$unit}";
            $small = $unit === 'meters' ? "{$reasonable} centimeters" : "{$reasonable} meters";
            $largeValue = $reasonable * 10;

            return $this->choiceProblem(
                "Three estimates are offered for the length of a {$object}. Which one is reasonable?",
                [$answer, $small, "{$largeValue} {$unit}"],
                $answer,
                ['Picture the object beside a ruler or meter stick.', 'Reject choices that would make the object extremely tiny or unusually large.'],
                "About {$answer} is a reasonable estimate for a {$object}."
            );
        }

        if ($variant === 2) {
            $first = $this->number(20, 60 + ($difficulty * 10), 143, $seed);
            $difference = $this->number(5, 10 + ($difficulty * 3), 144, $seed);
            $second = $first + $difference;

            return $this->numberProblem(
                "A ribbon is {$first} cm long. A second ribbon is {$second} cm long. How many centimeters must be trimmed from the second so both lengths match?",
                $difference,
                ['Both measurements already use centimeters.', 'Subtract the shorter length from the longer length.'],
                "{$second} − {$first} = {$difference} cm."
            );
        }

        $meters = $this->number(1, 3 + (int) floor($difficulty / 2), 145, $seed);
        $extraCentimeters = $this->number(10, 90, 146, $seed);
        $used = $this->number(5, $extraCentimeters, 147, $seed);
        $answer = ($meters * 100) + $extraCentimeters - $used;

        return $this->numberProblem(
            "A cord is {$meters} m {$extraCentimeters} cm long. After {$used} cm is used, how many centimeters remain?",
            $answer,
            ['Convert the meter part to centimeters first.', 'Add the extra centimeters, then subtract the amount used.'],
            "{$meters} m {$extraCentimeters} cm = " . (($meters * 100) + $extraCentimeters) . "; subtracting {$used} leaves {$answer} cm."
        );
    }

    private function metricConversion(
        string $largeUnit,
        string $smallUnit,
        int $factor,
        int $difficulty,
        ?int $seed
    ): array {
        $amount = $this->number(1, 2 + ($difficulty * 2), 89, $seed);
        $variant = $this->number($difficulty >= 4 ? 1 : 0, $difficulty >= 3 ? 3 : 1, 220, $seed);

        if ($variant === 1) {
            $extra = $this->number(1, max(1, (int) floor($factor * 0.9)), 90, $seed);
            $answer = ($amount * $factor) + $extra;
            $prompt = "A measurement is {$amount} {$largeUnit} and {$extra} {$smallUnit}. What is the entire amount in {$smallUnit}?";
            $hints = ["Convert the {$amount} {$largeUnit} part first.", "Then add the extra {$extra} {$smallUnit}."];
            $explanation = "{$amount} × {$factor} + {$extra} = {$answer} {$smallUnit}.";
        } elseif ($variant === 2) {
            $answer = $amount;
            $smallAmount = $amount * $factor;
            $prompt = "A container is labeled {$smallAmount} {$smallUnit}. How many {$largeUnit} is that?";
            $hints = ["One {$largeUnit} equals {$factor} {$smallUnit}.", "Divide {$smallAmount} by {$factor}."];
            $explanation = "{$smallAmount} ÷ {$factor} = {$answer} {$largeUnit}.";
        } elseif ($variant === 3) {
            $extra = $this->number(1, max(1, (int) floor($factor * 0.8)), 91, $seed);
            $used = $this->number(1, $extra, 92, $seed);
            $answer = ($amount * $factor) + $extra - $used;
            $prompt = "A supply holds {$amount} {$largeUnit} and {$extra} {$smallUnit}. After {$used} {$smallUnit} is used, how many {$smallUnit} remain?";
            $hints = ['Convert the mixed amount to the smaller unit.', 'Subtract the amount used.'];
            $explanation = "{$amount} × {$factor} + {$extra} − {$used} = {$answer} {$smallUnit}.";
        } else {
            $answer = $amount * $factor;
            $prompt = "Convert {$amount} {$largeUnit} to {$smallUnit}.";
            $hints = ["One {$largeUnit} equals {$factor} {$smallUnit}.", "Multiply {$amount} × {$factor}."];
            $explanation = "{$amount} {$largeUnit} = {$answer} {$smallUnit}.";
        }

        return $this->numberProblem(
            $prompt,
            $answer,
            $hints,
            $explanation
        );
    }

    private function mixedUnitConversion(int $difficulty, ?int $seed): array
    {
        $conversions = [
            ['m', 'cm', 100],
            ['kg', 'g', 1000],
            ['L', 'mL', 1000],
            ['hours', 'minutes', 60],
        ];
        [$large, $small, $factor] = $conversions[$this->number(0, 3, 90, $seed)];

        return $this->metricConversion($large, $small, $factor, $difficulty, $seed);
    }

    private function pictographProblem(int $difficulty, ?int $seed, int $minimumScale): array
    {
        $scales = $minimumScale === 1
            ? [1]
            : array_slice([2, 5, 10], 0, min(3, 1 + (int) ceil($difficulty / 2)));
        $scale = $scales[$this->number(0, count($scales) - 1, 107, $seed)];
        $categorySets = [
            ['Mango', 'Banana', 'Guava'],
            ['Robotics', 'Art', 'Music'],
            ['Cats', 'Dogs', 'Fish'],
            ['Red', 'Blue', 'Green'],
            ['Monday', 'Tuesday', 'Wednesday'],
        ];
        $categories = $categorySets[$this->number(0, count($categorySets) - 1, 108, $seed)];
        $low = $this->number(2, 3 + (int) ceil($difficulty / 2), 109, $seed);
        $gap = $this->number(2, 3 + $difficulty, 110, $seed);
        $high = min(9, $low + $gap);
        $middle = $this->number($low + 1, max($low + 1, $high - 1), 111, $seed);
        if ($middle >= $high) {
            $middle = max($low, $high - 1);
        }
        $counts = [$low, $high, $middle];
        $rows = [];
        foreach ($categories as $index => $category) {
            $rows[] = "{$category}: " . str_repeat('★', $counts[$index]);
        }
        $key = 'Key: ★ = ' . $scale . ' learner' . ($scale === 1 ? '' : 's');
        $display = "Pictograph data — {$key}\n" . implode("\n", $rows);
        $variant = $this->number(0, 5, 221, $seed);

        if ($variant === 4) {
            $counts[0] = min(4, $counts[0]);
            $counts[1] = $counts[0] * 2;
            $counts[2] = $counts[0] + 1;
            $rows[0] = "{$categories[0]}: " . str_repeat('★', $counts[0]);
            $rows[1] = "{$categories[1]}: " . str_repeat('★', $counts[1]);
            $rows[2] = "{$categories[2]}: " . str_repeat('★', $counts[2]);
            $display = "Pictograph data — {$key}\n" . implode("\n", $rows);
            $answer = $categories[1];

            return $this->choiceProblem(
                $display . "\nWhich category represents exactly twice as many learners as {$categories[0]}?",
                $categories,
                $answer,
                ['Compare the number of symbols in each row.', $scale === 1 ? 'Look for twice as many stars.' : 'The same key applies to every row, so doubling the symbols doubles the learners.'],
                "{$categories[0]} has {$counts[0]} symbols and {$categories[1]} has {$counts[1]}, which is twice as many."
            );
        }

        if ($variant === 5) {
            $shownRows = array_slice($rows, 0, 2);
            $grandTotal = array_sum($counts) * $scale;
            $answer = $counts[2] * $scale;
            $shown = "Incomplete pictograph — {$key}\n" . implode("\n", $shownRows) . "\n{$categories[2]}: ?";

            return $this->numberProblem(
                $shown . "\nAll three categories represent {$grandTotal} learners. How many learners belong in the missing {$categories[2]} row?",
                $answer,
                ['Find the learners represented by the two visible rows.', 'Subtract their combined amount from the total for all three rows.'],
                "The visible rows represent " . (($counts[0] + $counts[1]) * $scale) . "; {$grandTotal} minus that amount leaves {$answer} learners."
            );
        }

        $answer = match ($variant) {
            0 => ($counts[1] - $counts[0]) * $scale,
            1 => ($counts[0] + $counts[2]) * $scale,
            2 => array_sum($counts) * $scale,
            3 => ($counts[1] - $counts[0]) * $scale,
            default => 0,
        };
        $question = match ($variant) {
            0 => "How many more learners chose {$categories[1]} than {$categories[0]}?",
            1 => "How many learners chose either {$categories[0]} or {$categories[2]} altogether?",
            2 => 'How many learners are represented across all three categories?',
            default => "How many additional learners would {$categories[0]} need to tie {$categories[1]}?",
        };

        return $this->numberProblem(
            $display . "\n{$question}",
            $answer,
            [
                $variant === 2 ? 'Use all three rows.' : 'Identify the two rows named in the question.',
                $scale === 1
                    ? ($variant === 1 || $variant === 2 ? 'Add the relevant symbol counts.' : 'Find the difference between the relevant symbol counts.')
                    : "Each symbol represents {$scale} learners, so apply the key after combining or comparing the symbols.",
            ],
            "Using the rows and the scale of {$scale}, the requested value is {$answer} learners."
        );
    }

    private function comparisonGraphProblem(string $graph, int $difficulty, ?int $seed): array
    {
        $labels = $graph === 'line graph'
            ? ['Week 1', 'Week 2', 'Week 3', 'Week 4']
            : ['Mystery', 'Science', 'History', 'Comics'];
        $values = [];
        $base = $this->number(6, 10 + ($difficulty * 3), 170, $seed);
        for ($index = 0; $index < 4; $index++) {
            $values[] = max(2, $base + $this->number(-4, 5 + $difficulty, 171 + $index, $seed));
        }
        $variant = $this->number(0, 3, 222, $seed);

        if ($variant === 2) {
            $missingIndex = $this->number(1, 2, 175, $seed);
            $total = array_sum($values);
            $displayParts = [];
            foreach ($labels as $index => $label) {
                $displayParts[] = $label . ': ' . ($index === $missingIndex ? '?' : $values[$index]);
            }

            return $this->numberProblem(
                ucfirst($graph) . ' data — ' . implode('; ', $displayParts)
                    . ". The four values total {$total}. What value belongs at {$labels[$missingIndex]}?",
                $values[$missingIndex],
                ['Add the three visible values.', 'Subtract their sum from the four-value total.'],
                "The missing {$labels[$missingIndex]} value is {$values[$missingIndex]}."
            );
        }

        $displayParts = [];
        foreach ($labels as $index => $label) {
            $displayParts[] = "{$label}: {$values[$index]}";
        }
        $display = ucfirst($graph) . ' data — ' . implode('; ', $displayParts) . '.';

        if ($variant === 3) {
            $maximum = max($values);
            $maximumIndex = array_search($maximum, $values, true);
            while (count(array_keys($values, $maximum, true)) > 1) {
                $maximum++;
                $values[$maximumIndex] = $maximum;
            }
            $displayParts[$maximumIndex] = "{$labels[$maximumIndex]}: {$values[$maximumIndex]}";
            $display = ucfirst($graph) . ' data — ' . implode('; ', $displayParts) . '.';
            $answer = $labels[$maximumIndex];

            return $this->choiceProblem(
                "{$display} Which label has the greatest value?",
                $labels,
                $answer,
                ['Read all four values before deciding.', 'Compare the largest place values first.'],
                "{$answer} has the greatest value, {$values[$maximumIndex]}."
            );
        }

        if ($variant === 1) {
            $answer = abs($values[3] - $values[0]);

            return $this->numberProblem(
                "{$display} What is the absolute change from {$labels[0]} to {$labels[3]}?",
                $answer,
                ['Use the first and last values.', 'Subtract the smaller value from the larger value.'],
                "The absolute change is |{$values[3]} − {$values[0]}| = {$answer}."
            );
        }

        $answer = $values[1] + $values[2];

        return $this->numberProblem(
            "{$display} What is the combined value for {$labels[1]} and {$labels[2]}?",
            $answer,
            ['Locate the two requested labels.', 'Add their values rather than all four values.'],
            "{$values[1]} + {$values[2]} = {$answer}."
        );
    }

    private function likelihoodProblem(?int $seed): array
    {
        $colors = ['Red', 'Blue', 'Gold'];
        $counts = [
            $this->number(1, 9, 176, $seed),
            $this->number(1, 9, 177, $seed),
            $this->number(1, 9, 178, $seed),
        ];
        $firstIndex = $this->number(0, 2, 179, $seed);
        $secondIndex = ($firstIndex + $this->number(1, 2, 180, $seed)) % 3;
        $variant = $this->number(0, 2, 237, $seed);

        if ($variant === 2) {
            $counts[$secondIndex] = $counts[$firstIndex];
            $answer = 'Equally likely';
            $question = "How do the chances of drawing {$colors[$firstIndex]} and {$colors[$secondIndex]} compare?";
            $options = ["{$colors[$firstIndex]} is more likely", "{$colors[$secondIndex]} is more likely", 'Equally likely'];
        } else {
            if ($counts[$firstIndex] === $counts[$secondIndex]) {
                $counts[$secondIndex] = $counts[$secondIndex] === 9 ? 8 : $counts[$secondIndex] + 1;
            }
            $seekMore = $variant === 0;
            $winningIndex = $seekMore
                ? ($counts[$firstIndex] > $counts[$secondIndex] ? $firstIndex : $secondIndex)
                : ($counts[$firstIndex] < $counts[$secondIndex] ? $firstIndex : $secondIndex);
            $answer = $colors[$winningIndex];
            $question = 'Which of those two colors is ' . ($seekMore ? 'more' : 'less') . ' likely to be selected?';
            $options = [$colors[$firstIndex], $colors[$secondIndex], 'Equally likely'];
        }
        $display = "Bag contents — Red: {$counts[0]}; Blue: {$counts[1]}; Gold: {$counts[2]}.";

        return $this->choiceProblem(
            "{$display} {$question}",
            $options,
            $answer,
            ['Compare only the colors named in the question.', 'More copies mean more likely; equal copies mean equally likely.'],
            "The relevant counts are {$counts[$firstIndex]} and {$counts[$secondIndex]}, so the answer is {$answer}."
        );
    }

    private function doubleGraphProblem(int $difficulty, ?int $seed): array
    {
        $a = [];
        $b = [];
        for ($index = 0; $index < 3; $index++) {
            $a[] = $this->number(6, 12 + ($difficulty * 4), 181 + $index, $seed);
            $b[] = $this->number(6, 12 + ($difficulty * 4), 184 + $index, $seed);
        }
        $labels = ['Week 1', 'Week 2', 'Week 3'];
        $parts = [];
        foreach ($labels as $index => $label) {
            $parts[] = "{$label} (A {$a[$index]}, B {$b[$index]})";
        }
        $display = 'Double-graph data — ' . implode('; ', $parts) . '.';
        $variant = $this->number(0, 3, 238, $seed);

        if ($variant === 0) {
            $totalA = array_sum($a);
            $totalB = array_sum($b);
            $answer = abs($totalA - $totalB);

            return $this->numberProblem(
                "{$display} By how much do the three-week totals for Class A and Class B differ?",
                $answer,
                ['Find each class’s three-week total separately.', 'Subtract the smaller total from the larger total.'],
                "Class A totals {$totalA}, Class B totals {$totalB}, and their difference is {$answer}."
            );
        }

        if ($variant === 1) {
            $week = $this->number(0, 2, 187, $seed);
            $answer = abs($a[$week] - $b[$week]);

            return $this->numberProblem(
                "{$display} What is the difference between the classes in {$labels[$week]}?",
                $answer,
                ['Use both bars or lines for the specified week.', 'Subtract the smaller class value from the larger one.'],
                "|{$a[$week]} − {$b[$week]}| = {$answer}."
            );
        }

        if ($variant === 2) {
            $week = $this->number(0, 2, 188, $seed);
            $answer = $a[$week] + $b[$week];

            return $this->numberProblem(
                "{$display} How many items did both classes record altogether in {$labels[$week]}?",
                $answer,
                ['Read both class values for the same week.', 'Add those two values.'],
                "{$a[$week]} + {$b[$week]} = {$answer}."
            );
        }

        $a[2] = $a[0] + $this->number(2, 5 + $difficulty, 189, $seed);
        $b[2] = $b[0] + $this->number(2, 5 + $difficulty, 190, $seed);
        $parts[2] = "Week 3 (A {$a[2]}, B {$b[2]})";
        $display = 'Double-graph data — ' . implode('; ', $parts) . '.';
        $changeA = $a[2] - $a[0];
        $changeB = $b[2] - $b[0];
        if ($changeA === $changeB) {
            $b[2]++;
            $changeB++;
            $parts[2] = "Week 3 (A {$a[2]}, B {$b[2]})";
            $display = 'Double-graph data — ' . implode('; ', $parts) . '.';
        }
        $answer = $changeA > $changeB ? 'Class A' : 'Class B';

        return $this->choiceProblem(
            "{$display} Which class shows the greater increase from Week 1 to Week 3?",
            ['Class A', 'Class B', 'The increases are equal'],
            $answer,
            ['Find Week 3 minus Week 1 for each class.', 'Compare the two changes, not just the Week 3 heights.'],
            "Class A changes by {$changeA}; Class B changes by {$changeB}. Therefore, {$answer} has the greater increase."
        );
    }

    private function theoreticalProbability(int $difficulty, ?int $seed): array
    {
        $total = $this->number(6, 8 + $difficulty, 151, $seed);
        $green = $this->number(1, max(1, $total - 3), 152, $seed);
        $blue = $this->number(1, max(1, $total - $green - 1), 153, $seed);
        $other = $total - $green - $blue;
        $variant = $this->number(0, 2, 239, $seed);
        $favorable = match ($variant) {
            0 => $green,
            1 => $total - $green,
            default => $green + $blue,
        };
        $event = match ($variant) {
            0 => 'green',
            1 => 'not green',
            default => 'green or blue',
        };
        $answer = $this->fractionLabel($favorable, $total);
        $prompt = $this->promptVariant([
            "A bag has {$green} green, {$blue} blue, and {$other} gold tokens. One token is chosen at random. What is P({$event}) in simplest form?",
            "A spinner has {$total} equal sections: {$green} green, {$blue} blue, and {$other} gold. Find the simplified probability of landing on {$event}.",
            "Use the outcome counts green = {$green}, blue = {$blue}, gold = {$other}. What fraction gives the probability of {$event}? Simplify it.",
        ], $seed, 240);

        return $this->choiceProblem(
            $prompt,
            $this->fractionOptions($favorable, $total),
            $answer,
            ['Count every outcome included in the event.', 'Place favorable outcomes over all outcomes, then reduce the fraction.'],
            "There are {$favorable} favorable outcomes out of {$total}; {$favorable}/{$total} simplifies to {$answer}."
        );
    }

    private function pieGraphProblem(?int $seed): array
    {
        $percents = [10, 20, 25, 30, 40, 50, 60, 75, 80, 90];
        $percent = $percents[$this->number(0, count($percents) - 1, 101, $seed)];
        $angle = (int) round(3.6 * $percent);
        $variant = $this->number(0, 2, 223, $seed);

        if ($variant === 1) {
            return $this->numberProblem(
                "A sector measures {$angle}° in a pie graph. What percentage of the whole circle is it?",
                $percent,
                ['A full circle is 360°.', "Divide {$angle} by 360, then multiply by 100."],
                "{$angle} ÷ 360 × 100 = {$percent}%."
            );
        }

        if ($variant === 2) {
            $total = $this->number(2, 8, 224, $seed) * 20;
            $frequency = intdiv($percent * $total, 100);

            return $this->numberProblem(
                "A pie graph represents {$total} responses. One sector is {$percent}% of the circle. How many responses belong to that sector?",
                $frequency,
                ["Convert {$percent}% to {$percent}/100.", "Multiply {$total} by {$percent}/100."],
                "{$percent}% of {$total} is {$frequency} responses."
            );
        }

        return $this->numberProblem(
            "A category is {$percent}% of a pie graph. What angle should its sector measure?",
            $angle,
            ['A full circle is 360°.', "Multiply {$percent}% by 360°, or multiply {$percent} by 3.6."],
            "{$percent}% of 360° is {$angle}°."
        );
    }

    private function repeatingPattern(int $difficulty, ?int $seed): array
    {
        $tokenSets = [
            ['▲', '■', '●', '◆'],
            ['Red', 'Blue', 'Green', 'Gold'],
            ['A', 'B', 'C', 'D'],
            ['2', '5', '8', '11'],
            ['Sun', 'Moon', 'Star', 'Cloud'],
        ];
        $tokens = $tokenSets[$this->number(0, count($tokenSets) - 1, 116, $seed)];
        $cycleLength = $this->number(2, min(4, 2 + (int) ceil($difficulty / 2)), 117, $seed);
        $rotation = $this->number(0, count($tokens) - 1, 118, $seed);
        $cycle = [];
        for ($index = 0; $index < $cycleLength; $index++) {
            $cycle[] = $tokens[($rotation + $index) % count($tokens)];
        }

        if ($difficulty >= 4 && $this->number(0, 1, 119, $seed) === 1) {
            $targetPosition = $this->number($cycleLength * 2 + 1, $cycleLength * 4 + 2, 120, $seed);
            $answer = $cycle[($targetPosition - 1) % $cycleLength];

            return $this->choiceProblem(
                'The repeating unit is [' . implode(', ', $cycle) . "]. If it continues without gaps, which item is in position {$targetPosition}?",
                $tokens,
                $answer,
                ['Count positions through one complete repeating unit.', "Use the cycle length {$cycleLength} to locate the matching place in a later cycle."],
                "Position {$targetPosition} matches position " . ((($targetPosition - 1) % $cycleLength) + 1) . " of the repeating unit, so the item is {$answer}."
            );
        }

        $termCount = ($cycleLength * 3) + 1;
        $missingIndex = $this->number($cycleLength, $termCount - 2, 121, $seed);
        $sequence = [];
        for ($index = 0; $index < $termCount; $index++) {
            $sequence[] = $index === $missingIndex ? '__' : $cycle[$index % $cycleLength];
        }
        $answer = $cycle[$missingIndex % $cycleLength];

        return $this->choiceProblem(
            'One middle item is missing from this repeating pattern: ' . implode(', ', $sequence) . '. What restores the pattern?',
            $tokens,
            $answer,
            ['Find the shortest unit that repeats.', 'Match the blank with the same position in another complete unit.'],
            "The repeating unit is " . implode(', ', $cycle) . ", so the missing item is {$answer}."
        );
    }

    private function combinedPatternProblem(int $difficulty, ?int $seed): array
    {
        $symbolSets = [['A', 'B'], ['▲', '■'], ['R', 'S', 'T'], ['Sun', 'Moon']];
        $symbols = $symbolSets[$this->number(0, count($symbolSets) - 1, 122, $seed)];
        $step = $this->number(2, 3 + $difficulty, 123, $seed);
        $increasing = $this->number(0, 1, 124, $seed) === 1;
        $start = $this->number(2, 8 + $difficulty, 125, $seed);
        if (!$increasing) {
            $start += $step * 6;
        }
        $repeatEach = count($symbols) === 2 && $this->number(0, 1, 126, $seed) === 1 ? 2 : 1;
        $termCount = 7;
        $missingIndex = $this->number(2, $termCount - 1, 127, $seed);
        $sequence = [];
        $answer = '';
        for ($index = 0; $index < $termCount; $index++) {
            $value = $increasing ? $start + ($step * $index) : $start - ($step * $index);
            $symbolIndex = (int) floor($index / $repeatEach) % count($symbols);
            $term = $value . $symbols[$symbolIndex];
            if ($index === $missingIndex) {
                $answer = $term;
                $sequence[] = '__';
            } else {
                $sequence[] = $term;
            }
        }
        $answerValue = $increasing ? $start + ($step * $missingIndex) : $start - ($step * $missingIndex);
        $correctSymbolIndex = (int) floor($missingIndex / $repeatEach) % count($symbols);
        $wrongSymbol = $symbols[($correctSymbolIndex + 1) % count($symbols)];
        $wrongDirectionValue = $increasing ? $answerValue - $step : $answerValue + $step;

        return $this->choiceProblem(
            'The number rule and symbol rule operate together. Fill the blank: ' . implode(', ', $sequence) . '.',
            [$answer, $answerValue . $wrongSymbol, $wrongDirectionValue . $symbols[$correctSymbolIndex]],
            $answer,
            [
                'Track the numerical change separately from the symbol cycle.',
                'Apply both rules at the blank’s position.',
            ],
            'The numbers ' . ($increasing ? 'increase' : 'decrease') . " by {$step}, while the symbols repeat, so the missing term is {$answer}."
        );
    }

    private function numericPattern(int $difficulty, ?int $seed, bool $askRule): array
    {
        $step = $this->number(2, 3 + $difficulty, 103, $seed);
        $start = $this->number(1, 8 + $difficulty, 104, $seed);
        $increasing = $this->number(0, 1, 105, $seed) === 1;
        if (!$increasing) {
            $start += $step * 4;
        }
        $terms = [$start];
        for ($index = 1; $index < 4; $index++) {
            $terms[] = $increasing ? $terms[$index - 1] + $step : $terms[$index - 1] - $step;
        }

        if ($askRule) {
            $answer = ($increasing ? 'Add ' : 'Subtract ') . $step;
            $prompt = $this->promptVariant([
                'What rule generates this pattern: ' . implode(', ', $terms) . '?',
                'Choose the constant-change rule for ' . implode(', ', $terms) . '.',
                'How does each term change to make this sequence: ' . implode(', ', $terms) . '?',
            ], $seed, 240);

            return $this->choiceProblem(
                $prompt,
                [$answer, ($increasing ? 'Subtract ' : 'Add ') . $step, 'Multiply by 2'],
                $answer,
                ['Compare each term with the term before it.', 'Look for a constant difference.'],
                "The rule is “{$answer}” each time."
            );
        }

        $next = $increasing ? end($terms) + $step : end($terms) - $step;
        $prompt = $this->promptVariant([
            'Find the next term: ' . implode(', ', $terms) . ', __',
            'Continue this increasing or decreasing sequence: ' . implode(', ', $terms) . ', __',
            'What number follows ' . implode(', ', $terms) . ' if the same change continues?',
        ], $seed, 241);

        return $this->numberProblem(
            $prompt,
            $next,
            ['Find the constant difference between terms.', ($increasing ? 'Add ' : 'Subtract ') . "{$step} once more."],
            "The pattern continues with {$next}."
        );
    }

    private function similarFractionOperation(int $difficulty, ?int $seed): array
    {
        $denominator = $this->number(3, 7 + $difficulty, 106, $seed);
        $first = $this->number(1, $denominator - 1, 107, $seed);
        $second = $this->number(1, $denominator - 1, 108, $seed);
        $addition = $this->number(0, 1, 109, $seed) === 1;
        if (!$addition && $second > $first) {
            [$first, $second] = [$second, $first];
        }
        $resultNumerator = $addition ? $first + $second : $first - $second;
        $symbol = $addition ? '+' : '−';
        $answer = $this->fractionLabel($resultNumerator, $denominator);
        $prompt = $this->promptVariant([
            "Calculate {$first}/{$denominator} {$symbol} {$second}/{$denominator}. Give the complete answer in simplest form.",
            $addition
                ? "A class completed {$first}/{$denominator} of a mural in the morning and {$second}/{$denominator} in the afternoon. What fraction was completed altogether? Simplify."
                : "A ribbon was {$first}/{$denominator} m long and {$second}/{$denominator} m was used. What fraction of a meter remains? Simplify.",
            "Which simplified fraction is equivalent to {$first}/{$denominator} {$symbol} {$second}/{$denominator}?",
        ], $seed, 249);

        return $this->choiceProblem(
            $prompt,
            $this->fractionOptions($resultNumerator, $denominator),
            $answer,
            ['The denominators already match, so keep the denominator.', ($addition ? 'Add' : 'Subtract') . ' the numerators.'],
            "{$first}/{$denominator} {$symbol} {$second}/{$denominator} = {$resultNumerator}/{$denominator} = {$answer}."
        );
    }

    private function dissimilarFractionOperation(int $difficulty, ?int $seed): array
    {
        $firstDenominator = [2, 3, 4][$this->number(0, 2, 110, $seed)];
        $scale = $this->number(2, min(4, 2 + $difficulty), 111, $seed);
        $commonDenominator = $firstDenominator * $scale;
        $firstNumerator = $this->number(1, $firstDenominator - 1, 112, $seed);
        $convertedFirst = $firstNumerator * $scale;
        $addition = $this->number(0, 1, 154, $seed) === 1;
        $secondMaximum = $addition
            ? max(1, $commonDenominator - 1)
            : max(1, $convertedFirst);
        $secondNumerator = $this->number(1, $secondMaximum, 113, $seed);
        $resultNumerator = $addition
            ? $convertedFirst + $secondNumerator
            : $convertedFirst - $secondNumerator;
        $symbol = $addition ? '+' : '−';
        $answer = $this->fractionLabel($resultNumerator, $commonDenominator);
        $prompt = $this->promptVariant([
            "Calculate {$firstNumerator}/{$firstDenominator} {$symbol} {$secondNumerator}/{$commonDenominator}. Give the result in simplest form.",
            $addition
                ? "A trail covers {$firstNumerator}/{$firstDenominator} km before a break and {$secondNumerator}/{$commonDenominator} km after it. What total distance was covered? Answer as a simplified fraction."
                : "A container held {$firstNumerator}/{$firstDenominator} L and used {$secondNumerator}/{$commonDenominator} L. What fraction of a liter remains? Simplify.",
            "Find the simplest fraction equal to {$firstNumerator}/{$firstDenominator} {$symbol} {$secondNumerator}/{$commonDenominator}.",
        ], $seed, 250);

        return $this->choiceProblem(
            $prompt,
            $this->fractionOptions($resultNumerator, $commonDenominator),
            $answer,
            ["Rewrite {$firstNumerator}/{$firstDenominator} with denominator {$commonDenominator}.", 'Then ' . ($addition ? 'add' : 'subtract') . ' the numerators and simplify.'],
            "{$firstNumerator}/{$firstDenominator} = {$convertedFirst}/{$commonDenominator}; the result is {$resultNumerator}/{$commonDenominator} = {$answer}."
        );
    }

    private function multiplyFractions(int $difficulty, ?int $seed): array
    {
        $a = $this->number(1, 2 + $difficulty, 114, $seed);
        $b = $a + $this->number(1, 3, 115, $seed);
        $c = $this->number(1, 2 + $difficulty, 116, $seed);
        $d = $c + $this->number(1, 3, 117, $seed);
        $productNumerator = $a * $c;
        $resultDenominator = $b * $d;
        $extraNumerator = $difficulty >= 4
            ? $this->number(1, min($difficulty, $resultDenominator - 1), 118, $seed)
            : 0;
        $resultNumerator = $productNumerator + $extraNumerator;
        $answer = $this->fractionLabel($resultNumerator, $resultDenominator);
        $prompt = $this->promptVariant([
            $extraNumerator > 0
                ? "Calculate {$a}/{$b} × {$c}/{$d} + {$extraNumerator}/{$resultDenominator}. Give the final result in simplest form."
                : "Calculate {$a}/{$b} × {$c}/{$d}. Give the product in simplest form.",
            $extraNumerator > 0
                ? "Flowers cover {$c}/{$d} of {$a}/{$b} of a plot, and another {$extraNumerator}/{$resultDenominator} of the whole plot is planted separately. What fraction of the plot has flowers altogether?"
                : "A garden uses {$a}/{$b} of a plot, and flowers cover {$c}/{$d} of that used part. What fraction of the whole plot has flowers? Simplify.",
            $extraNumerator > 0
                ? "Find the product {$a}/{$b} × {$c}/{$d}, then add {$extraNumerator}/{$resultDenominator}. Which simplified fraction is the final value?"
                : "An area model overlaps {$a}/{$b} in one direction and {$c}/{$d} in the other. Which simplified fraction is the overlap?",
        ], $seed, 251);

        return $this->choiceProblem(
            $prompt,
            $this->fractionOptions($resultNumerator, $resultDenominator),
            $answer,
            [
                'Multiply the numerators and denominators first.',
                $extraNumerator > 0 ? 'Add the fraction with the matching denominator, then reduce.' : 'Reduce the resulting fraction by their greatest common factor.',
            ],
            $extraNumerator > 0
                ? "The product is {$productNumerator}/{$resultDenominator}; adding {$extraNumerator}/{$resultDenominator} gives {$resultNumerator}/{$resultDenominator} = {$answer}."
                : "The product is {$resultNumerator}/{$resultDenominator}, which simplifies to {$answer}."
        );
    }

    private function divideFractions(int $difficulty, ?int $seed): array
    {
        $a = $this->number(1, 2 + $difficulty, 118, $seed);
        $b = $a + $this->number(1, 3, 119, $seed);
        $c = $this->number(1, 2 + $difficulty, 120, $seed);
        $d = $c + $this->number(1, 3, 121, $seed);
        $quotientNumerator = $a * $d;
        $resultDenominator = $b * $c;
        $removedNumerator = $difficulty >= 4
            ? $this->number(1, min($difficulty, $quotientNumerator - 1), 122, $seed)
            : 0;
        $resultNumerator = $quotientNumerator - $removedNumerator;
        $answer = $this->fractionLabel($resultNumerator, $resultDenominator);
        $prompt = $this->promptVariant([
            $removedNumerator > 0
                ? "Calculate {$a}/{$b} ÷ {$c}/{$d} − {$removedNumerator}/{$resultDenominator}. Give the final result in simplest form."
                : "Calculate {$a}/{$b} ÷ {$c}/{$d}. Give the quotient in simplest form.",
            $removedNumerator > 0
                ? "Find how many {$c}/{$d}-sized portions fit into {$a}/{$b}, then remove {$removedNumerator}/{$resultDenominator} of a portion. What simplified amount remains?"
                : "How many groups of size {$c}/{$d} fit into {$a}/{$b}? Choose the simplified quotient.",
            $removedNumerator > 0
                ? "Use a reciprocal for {$a}/{$b} ÷ {$c}/{$d}, then subtract {$removedNumerator}/{$resultDenominator}. Which simplified result is correct?"
                : "Rewrite {$a}/{$b} ÷ {$c}/{$d} using a reciprocal, then select the complete simplified result.",
        ], $seed, 252);

        return $this->choiceProblem(
            $prompt,
            $this->fractionOptions($resultNumerator, $resultDenominator),
            $answer,
            [
                "Use the reciprocal {$d}/{$c}.",
                $removedNumerator > 0 ? 'Subtract the fraction with the matching denominator, then reduce.' : 'Multiply, then reduce the complete fraction.',
            ],
            $removedNumerator > 0
                ? "The quotient is {$quotientNumerator}/{$resultDenominator}; subtracting {$removedNumerator}/{$resultDenominator} gives {$resultNumerator}/{$resultDenominator} = {$answer}."
                : "{$a}/{$b} × {$d}/{$c} = {$resultNumerator}/{$resultDenominator} = {$answer}."
        );
    }

    private function mixedFractionOperation(int $difficulty, ?int $seed): array
    {
        if ($difficulty >= 4) {
            $wholePart = $this->number(1, 2 + $difficulty, 122, $seed);
            $mixedDenominator = $this->number(2, 5 + $difficulty, 123, $seed);
            $mixedNumerator = $this->number(1, $mixedDenominator - 1, 124, $seed);
            $otherNumerator = $this->number(1, 2 + $difficulty, 125, $seed);
            $otherDenominator = $otherNumerator + $this->number(1, 4, 126, $seed);
            $improperNumerator = ($wholePart * $mixedDenominator) + $mixedNumerator;
            $multiply = $this->number(0, 1, 177, $seed) === 1;
            $resultNumerator = $improperNumerator * ($multiply ? $otherNumerator : $otherDenominator);
            $resultDenominator = $mixedDenominator * ($multiply ? $otherDenominator : $otherNumerator);
            $answer = $this->fractionLabel($resultNumerator, $resultDenominator);
            $mixed = "{$wholePart} {$mixedNumerator}/{$mixedDenominator}";
            $other = "{$otherNumerator}/{$otherDenominator}";
            $symbol = $multiply ? '×' : '÷';
            $prompt = $this->promptVariant([
                "Calculate {$mixed} {$symbol} {$other}. Give the complete result in simplest form.",
                $multiply
                    ? "A recipe uses {$mixed} cups per batch for {$other} of a batch. How many cups are needed? Choose the simplified result."
                    : "A {$mixed}-liter supply is separated into portions of {$other} liter. How many portions does the division represent? Choose the exact simplified result.",
                "Convert the mixed number, then evaluate {$mixed} {$symbol} {$other}. Which simplified value is correct?",
            ], $seed, 253);

            return $this->choiceProblem(
                $prompt,
                $this->fractionOptions($resultNumerator, $resultDenominator),
                $answer,
                [
                    "Rewrite {$mixed} as {$improperNumerator}/{$mixedDenominator}.",
                    $multiply ? 'Multiply the fractions, then reduce.' : "Multiply by the reciprocal {$otherDenominator}/{$otherNumerator}, then reduce.",
                ],
                "{$mixed} = {$improperNumerator}/{$mixedDenominator}; after {$symbol} {$other}, the simplified result is {$answer}."
            );
        }

        $whole = $this->number(2, 3 + $difficulty, 122, $seed);
        $numerator = $this->number(1, 3 + $difficulty, 123, $seed);
        $denominator = $numerator + $this->number(1, 4, 124, $seed);
        $multiply = $this->number(0, 1, 177, $seed) === 1;
        $resultNumerator = $whole * ($multiply ? $numerator : $denominator);
        $resultDenominator = $multiply ? $denominator : $numerator;
        $answer = $this->fractionLabel($resultNumerator, $resultDenominator);
        $prompt = $multiply
            ? $this->promptVariant([
                "Calculate {$whole} × {$numerator}/{$denominator}. Give the complete answer in simplest form.",
                "Each of {$whole} containers holds {$numerator}/{$denominator} L. How many liters are there altogether? Choose the simplified result.",
                "Which simplified value is equivalent to {$whole}/1 × {$numerator}/{$denominator}?",
            ], $seed, 253)
            : $this->promptVariant([
                "Calculate {$whole} ÷ {$numerator}/{$denominator}. Give the complete answer in simplest form.",
                "A {$whole}-liter supply is poured into portions of {$numerator}/{$denominator} L. How many portions can be filled? Choose the exact result.",
                "Rewrite {$whole} ÷ {$numerator}/{$denominator} with a reciprocal, then simplify the quotient.",
            ], $seed, 254);

        return $this->choiceProblem(
            $prompt,
            $this->fractionOptions($resultNumerator, $resultDenominator),
            $answer,
            $multiply
                ? ['Write the whole number over 1.', 'Multiply and reduce the complete fraction.']
                : ["Use the reciprocal {$denominator}/{$numerator}.", 'Multiply and reduce the complete fraction.'],
            $multiply
                ? "{$whole} × {$numerator}/{$denominator} = {$resultNumerator}/{$resultDenominator} = {$answer}."
                : "{$whole}/1 × {$denominator}/{$numerator} = {$resultNumerator}/{$resultDenominator} = {$answer}."
        );
    }

    private function elapsedTime(int $difficulty, ?int $seed): array
    {
        $startHour = $this->number(7, 18, 155, $seed);
        $startMinuteOptions = array_slice([0, 15, 30, 45], 0, min(4, 1 + $difficulty));
        $startMinute = $startMinuteOptions[$this->number(0, count($startMinuteOptions) - 1, 156, $seed)];
        $durations = [30, 45, 60, 75, 90, 105, 120];
        $duration = $durations[$this->number(0, min(count($durations) - 1, 1 + $difficulty), 157, $seed)];
        $pause = $difficulty >= 4 ? $this->number(10, 30, 158, $seed) : 0;
        $startTotal = ($startHour * 60) + $startMinute;
        $endTotal = $startTotal + $duration + $pause;
        $start = $this->clockLabelWithMeridiem($startTotal);
        $answer = $this->clockLabelWithMeridiem($endTotal);
        $prompt = $pause > 0
            ? "A workshop starts at {$start}, includes {$duration} minutes of activities and a {$pause}-minute break. At what time does everything finish?"
            : $this->promptVariant([
                "A workshop starts at {$start} and lasts {$duration} minutes. At what time does it finish?",
                "A trip begins at {$start}. Which clock time is {$duration} minutes later?",
                "Move {$duration} minutes forward from {$start}. What time do you reach?",
            ], $seed, 255);

        return $this->choiceProblem(
            $prompt,
            [
                $answer,
                $this->clockLabelWithMeridiem($endTotal - 15),
                $this->clockLabelWithMeridiem($endTotal + 15),
            ],
            $answer,
            ['Break the duration into hours and minutes.', $pause > 0 ? 'Add both the activity time and the break.' : 'Count forward across the next hour when needed.'],
            "{$duration}" . ($pause > 0 ? " + {$pause}" : '') . " minutes after {$start} is {$answer}."
        );
    }

    private function timeSystemConversion(?int $seed): array
    {
        $hour = $this->number(0, 23, 159, $seed);
        $minute = [0, 15, 30, 45][$this->number(0, 3, 160, $seed)];
        $total = ($hour * 60) + $minute;
        $convertToTwelve = $this->number(0, 1, 256, $seed) === 1;
        $twelveHour = $this->clockLabelWithMeridiem($total);
        $twentyFourHour = $this->twentyFourHourLabel($total);

        if ($convertToTwelve) {
            $oppositePeriod = str_contains($twelveHour, 'a.m.')
                ? str_replace('a.m.', 'p.m.', $twelveHour)
                : str_replace('p.m.', 'a.m.', $twelveHour);
            $wrongHour = $this->clockLabelWithMeridiem($total + 60);
            $prompt = $this->promptVariant([
                "A transport board lists {$twentyFourHour}. Which 12-hour time represents the same moment?",
                "Convert the timetable entry {$twentyFourHour} to a 12-hour time with a.m. or p.m.",
                "An event begins at {$twentyFourHour}. How should that time be written on a 12-hour clock?",
            ], $seed, 256);

            return $this->choiceProblem(
                $prompt,
                [$twelveHour, $oppositePeriod, $wrongHour],
                $twelveHour,
                ['Decide whether the 24-hour time falls before or after noon.', 'For hours above 12, subtract 12 but keep the minutes.'],
                "{$twentyFourHour} is {$twelveHour}."
            );
        }

        $wrongPeriod = ($total + (12 * 60)) % (24 * 60);
        $prompt = $this->promptVariant([
            "A schedule gives the time as {$twelveHour}. Which 24-hour entry matches it?",
            "Convert {$twelveHour} to 24-hour notation.",
            "A 12-hour clock shows {$twelveHour}. How will a transport timetable record that moment?",
        ], $seed, 257);

        return $this->choiceProblem(
            $prompt,
            [$twentyFourHour, $this->twentyFourHourLabel($wrongPeriod), $this->twentyFourHourLabel($total + 60)],
            $twentyFourHour,
            ['Use 00 for the midnight hour and 12 for the noon hour.', 'For p.m. times after 12 noon, add 12 to the hour and keep the minutes.'],
            "{$twelveHour} is written {$twentyFourHour} in 24-hour time."
        );
    }

    private function timeZoneProblem(int $difficulty, ?int $seed): array
    {
        $manilaHour = $this->number(0, 23, 161, $seed);
        $minute = [0, 30][$this->number(0, 1, 162, $seed)];
        $difference = $this->number(1, min(8, 2 + $difficulty), 163, $seed);
        $ahead = $this->number(0, 1, 164, $seed) === 1;
        $manilaTotal = ($manilaHour * 60) + $minute;
        $localTotal = $manilaTotal + (($ahead ? $difference : -$difference) * 60);
        $answer = $this->twentyFourHourLabel($localTotal);
        $manilaLabel = $this->twentyFourHourLabel($manilaTotal);
        $dayNote = $localTotal < 0 ? ' on the previous day' : ($localTotal >= 24 * 60 ? ' on the next day' : ' on the same day');
        $prompt = $this->promptVariant([
            "It is {$manilaLabel} in the Philippines. A city is {$difference} hour" . ($difference === 1 ? '' : 's') . ($ahead ? ' ahead' : ' behind') . '. What is the local 24-hour time there?',
            "A live call begins at {$manilaLabel} Philippine time. The other city is {$difference} hour" . ($difference === 1 ? '' : 's') . ($ahead ? ' ahead' : ' behind') . '. What time appears on its 24-hour clock?',
            "Convert {$manilaLabel} from Philippine time to a zone {$difference} hour" . ($difference === 1 ? '' : 's') . ($ahead ? ' ahead' : ' behind') . '. Give the local 24-hour time.',
        ], $seed, 258);

        return $this->choiceProblem(
            $prompt,
            [$answer, $this->twentyFourHourLabel($localTotal - 60), $this->twentyFourHourLabel($localTotal + 60)],
            $answer,
            [$ahead ? 'Add the time difference.' : 'Subtract the time difference.', 'Keep the minutes unchanged and check whether the date boundary is crossed.'],
            "The local time is {$answer}{$dayNote}."
        );
    }

    private function turnProblem(int $difficulty, ?int $seed): array
    {
        $directions = ['North', 'East', 'South', 'West'];
        $startIndex = $this->number(0, 3, 131, $seed);
        $quarterTurns = $this->number(1, 2, 132, $seed);
        $clockwise = $this->number(0, 1, 133, $seed) === 1;
        $secondQuarterTurns = $difficulty >= 4 ? $this->number(1, 2, 134, $seed) : 0;
        $secondClockwise = $this->number(0, 1, 135, $seed) === 1;
        $offset = ($clockwise ? $quarterTurns : -$quarterTurns)
            + ($secondClockwise ? $secondQuarterTurns : -$secondQuarterTurns);
        $answer = $directions[($startIndex + $offset + 8) % 4];
        $turnName = $quarterTurns === 2 ? 'half turn' : 'quarter turn';
        $secondTurn = $secondQuarterTurns > 0
            ? ', then a ' . ($secondQuarterTurns === 2 ? 'half turn' : 'quarter turn') . ' ' . ($secondClockwise ? 'clockwise' : 'counterclockwise')
            : '';

        return $this->choiceProblem(
            "An arrow faces {$directions[$startIndex]}. It makes a {$turnName} " . ($clockwise ? 'clockwise' : 'counterclockwise') . "{$secondTurn}. Which direction does it face at the end?",
            $directions,
            $answer,
            ['A quarter turn is 90° and a half turn is 180°.', $secondQuarterTurns > 0 ? 'Track the first direction before applying the second turn.' : 'Trace the turn from the starting direction.'],
            "The arrow finishes facing {$answer}."
        );
    }

    private function translationProblem(int $difficulty, ?int $seed): array
    {
        $firstSteps = $this->number(1, 2 + $difficulty, 136, $seed);
        $secondSteps = $difficulty >= 3 ? $this->number(1, 2 + $difficulty, 137, $seed) : 0;
        $right = $this->number(0, 1, 138, $seed) === 1;
        $x = $right
            ? $this->number(0, 5 + $difficulty, 139, $seed)
            : $this->number($firstSteps + $secondSteps, 10 + ($difficulty * 2), 139, $seed);
        $totalSteps = $firstSteps + $secondSteps;
        $answer = $right ? $x + $totalSteps : $x - $totalSteps;
        $direction = $right ? 'right' : 'left';
        $secondSlide = $secondSteps > 0 ? ", then another {$secondSteps} columns {$direction}" : '';
        $prompt = $this->promptVariant([
            "A marker starts in column {$x} and slides {$firstSteps} columns {$direction}{$secondSlide}. Which column does it reach?",
            "Translate a figure from column {$x}: first {$firstSteps} spaces {$direction}" . ($secondSteps > 0 ? ", then {$secondSteps} more spaces {$direction}" : '') . '. What is its final column?',
            "Track this slide without turning the figure: start at {$x}, move {$firstSteps} {$direction}" . ($secondSteps > 0 ? ", then {$secondSteps} {$direction}" : '') . '. Where does it finish?',
        ], $seed, 257);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Combine consecutive moves in the same direction.', $right ? 'Moving right increases the column number.' : 'Moving left decreases the column number.'],
            "Starting at {$x} and moving {$totalSteps} columns {$direction} finishes at {$answer}."
        );
    }

    private function twoDirectionTranslationProblem(int $difficulty, ?int $seed): array
    {
        $x = $this->number(2, 5 + $difficulty, 181, $seed);
        $y = $this->number(2, 5 + $difficulty, 182, $seed);
        $horizontal = $this->number(1, 2 + $difficulty, 183, $seed);
        $vertical = $this->number(1, 2 + $difficulty, 184, $seed);
        $right = $this->number(0, 1, 185, $seed) === 1;
        $up = $this->number(0, 1, 186, $seed) === 1;
        $dx = $right ? $horizontal : -$horizontal;
        $dy = $up ? $vertical : -$vertical;
        $answer = '(' . ($x + $dx) . ', ' . ($y + $dy) . ')';

        return $this->choiceProblem(
            "A marker at grid position ({$x}, {$y}) slides {$horizontal} "
                . ($right ? 'right' : 'left')
                . " and {$vertical} "
                . ($up ? 'up' : 'down')
                . '. Where does it finish?',
            [
                $answer,
                '(' . ($x - $dx) . ', ' . ($y + $dy) . ')',
                '(' . ($x + $dx) . ', ' . ($y - $dy) . ')',
            ],
            $answer,
            ['Apply the horizontal slide first.', 'Then apply the vertical slide without changing the marker.'],
            "The marker finishes at {$answer}."
        );
    }

    private function reflectionProblem(int $difficulty, ?int $seed): array
    {
        $x = $this->number(1, 3 + $difficulty, 136, $seed);
        $y = $this->number(1, 3 + $difficulty, 137, $seed);
        $reflectAcrossY = $this->number(0, 1, 138, $seed) === 1;
        $glide = $difficulty >= 4 ? $this->number(1, 2 + $difficulty, 139, $seed) : 0;
        $reflectedX = $reflectAcrossY ? -$x : $x;
        $reflectedY = $reflectAcrossY ? $y : -$y;
        $finalX = $reflectAcrossY ? $reflectedX : $reflectedX + $glide;
        $finalY = $reflectAcrossY ? $reflectedY + $glide : $reflectedY;
        $axis = $reflectAcrossY ? 'y-axis' : 'x-axis';
        $glideText = $glide > 0
            ? ($reflectAcrossY ? ", then slides {$glide} units up" : ", then slides {$glide} units right")
            : '';
        $answer = "({$finalX}, {$finalY})";
        $options = [
            $answer,
            "(" . (-$finalX) . ", {$finalY})",
            "({$finalX}, " . (-$finalY) . ')',
            "({$y}, {$x})",
        ];

        $prompt = $this->promptVariant([
            "Point ({$x}, {$y}) is reflected across the {$axis}{$glideText}. Which coordinate is its final image?",
            "Mirror ({$x}, {$y}) over the {$axis}{$glideText}. Where does the image finish?",
            "A transformation reflects ({$x}, {$y}) in the {$axis}{$glideText}. Select the resulting coordinate.",
        ], $seed, 258);

        return $this->choiceProblem(
            $prompt,
            $options,
            $answer,
            ['A reflection changes only the coordinate perpendicular to the mirror axis.', $glide > 0 ? 'Apply the slide after finding the reflected point.' : 'The other coordinate stays unchanged.'],
            "After the reflection" . ($glide > 0 ? ' and glide' : '') . ", the point is {$answer}."
        );
    }

    private function rotationProblem(int $difficulty, ?int $seed): array
    {
        $x = $this->number(1, 3 + $difficulty, 140, $seed);
        $y = $this->number(1, 3 + $difficulty, 141, $seed);
        $turns = $this->number(1, $difficulty >= 4 ? 3 : 2, 142, $seed);
        $clockwise = $this->number(0, 1, 143, $seed) === 1;
        $normalizedTurns = (($clockwise ? $turns : -$turns) % 4 + 4) % 4;
        [$answerX, $answerY] = match ($normalizedTurns) {
            1 => [$y, -$x],
            2 => [-$x, -$y],
            3 => [-$y, $x],
            default => [$x, $y],
        };
        $answer = "({$answerX}, {$answerY})";
        $angle = $turns * 90;
        $options = [
            "({$x}, {$y})",
            "({$y}, " . (-$x) . ')',
            '(' . (-$x) . ', ' . (-$y) . ')',
            '(' . (-$y) . ", {$x})",
        ];

        $prompt = $this->promptVariant([
            "Point ({$x}, {$y}) rotates {$angle}° " . ($clockwise ? 'clockwise' : 'counterclockwise') . ' about the origin. Which coordinate is its image?',
            "Turn ({$x}, {$y}) {$angle}° " . ($clockwise ? 'clockwise' : 'counterclockwise') . ' around (0, 0). Where does it land?',
            "Which coordinate results when ({$x}, {$y}) makes a {$angle}° " . ($clockwise ? 'clockwise' : 'counterclockwise') . ' rotation about the origin?',
        ], $seed, 259);

        return $this->choiceProblem(
            $prompt,
            $options,
            $answer,
            ['Track one quarter-turn at a time around the origin.', 'The point’s distance from the origin does not change.'],
            "After a {$angle}° " . ($clockwise ? 'clockwise' : 'counterclockwise') . " rotation, the image is {$answer}."
        );
    }

    private function multiplicationProperty(int $difficulty, ?int $seed): array
    {
        $a = $this->number(2, 9, 138, $seed);
        $b = $this->number(2, 9, 139, $seed);
        $c = $this->number(2, 6, 140, $seed);
        $variantMinimum = $difficulty >= 4 ? 3 : 0;
        $variant = $this->number($variantMinimum, 5, 260, $seed);

        if ($variant === 4) {
            $answer = "({$a} × {$b}) + ({$a} × {$c})";

            return $this->choiceProblem(
                "Which expression correctly uses the distributive property to rewrite {$a} × ({$b} + {$c})?",
                [
                    $answer,
                    "({$a} + {$b}) × ({$a} + {$c})",
                    "({$a} × {$b}) + {$c}",
                    "{$a} + ({$b} × {$c})",
                ],
                $answer,
                ['The factor outside the parentheses multiplies both addends.', 'Keep the two partial products joined by addition.'],
                "{$a} × ({$b} + {$c}) = ({$a} × {$b}) + ({$a} × {$c})."
            );
        }

        if ($variant === 5) {
            $wrong = "{$a} + ({$b} × {$c})";
            $answer = "({$a} × {$b}) + ({$a} × {$c})";

            return $this->choiceProblem(
                "A learner rewrites {$a} × ({$b} + {$c}) as {$wrong}. Which correction preserves the original product?",
                [
                    $answer,
                    "({$a} + {$b}) × ({$a} + {$c})",
                    "({$a} × {$b}) + {$c}",
                ],
                $answer,
                ['Check whether the outside factor was applied to every term.', 'You can evaluate both expressions to verify the correction.'],
                "The {$a} must multiply both {$b} and {$c}, giving {$answer}."
            );
        }

        if ($variant === 3) {
            $answer = "{$a} × ({$b} × {$c})";

            return $this->choiceProblem(
                "Which expression is equivalent to ({$a} × {$b}) × {$c} by the associative property?",
                [
                    $answer,
                    "({$a} + {$b}) × {$c}",
                    "{$a} × ({$b} + {$c})",
                ],
                $answer,
                ['The associative property changes grouping.', 'Keep all factors and their order the same.'],
                "({$a} × {$b}) × {$c} = {$a} × ({$b} × {$c})."
            );
        }

        if ($variant === 2) {
            return $this->choiceProblem(
                "A box has {$b} rows with zero counters in every row. Which equation explains the total using the zero property?",
                ["{$b} × 0 = 0", "{$b} × 0 = {$b}", "{$b} + 0 = 0"],
                "{$b} × 0 = 0",
                ['Zero counters repeated any number of times still totals zero.', 'Do not confuse multiplication by zero with adding zero.'],
                "{$b} rows × 0 counters per row = 0 counters."
            );
        }

        if ($variant === 1) {
            return $this->choiceProblem(
                "Which equation uses the identity property to keep {$a} unchanged?",
                ["{$a} × 1 = {$a}", "{$a} × 0 = {$a}", "{$a} + 1 = {$a}"],
                "{$a} × 1 = {$a}",
                ['The multiplicative identity leaves a factor unchanged.', 'Compare what multiplying by 1 and multiplying by 0 do.'],
                "Multiplying by 1 keeps the value: {$a} × 1 = {$a}."
            );
        }

        return $this->choiceProblem(
            "A {$a}-by-{$b} array is turned so it has {$b} rows of {$a}. Which equation shows why its total stays the same?",
            ["{$a} × {$b} = {$b} × {$a}", "{$a} + {$b} = {$a} × {$b}", "{$a} × 1 = {$b}"],
            "{$a} × {$b} = {$b} × {$a}",
            ['Turning the array swaps its rows and columns.', 'The commutative property changes factor order without changing the product.'],
            "The same array shows {$a} × {$b} = {$b} × {$a}."
        );
    }

    private function estimateProduct(int $difficulty, ?int $seed): array
    {
        $a = $this->number(12, 40 + ($difficulty * 20), 140, $seed);
        $b = $this->number(12, 40 + ($difficulty * 10), 141, $seed);
        $roundedA = (int) (round($a / 10) * 10);
        $roundedB = (int) (round($b / 10) * 10);
        $answer = $roundedA * $roundedB;
        $prompt = $this->promptVariant([
            "Estimate {$a} × {$b} by rounding both factors to the nearest ten.",
            "A quick estimate is needed for {$a} groups of {$b}. Round each factor to the nearest ten, then multiply.",
            "Use compatible tens to approximate the product {$a} × {$b}.",
        ], $seed, 261);

        return $this->numberProblem(
            $prompt,
            $answer,
            ["{$a} rounds to {$roundedA} and {$b} rounds to {$roundedB}.", 'Multiply the rounded factors.'],
            "{$roundedA} × {$roundedB} = {$answer}."
        );
    }

    private function multiDigitDivision(int $difficulty, ?int $seed): array
    {
        $divisor = $this->number(2, $difficulty >= 4 ? 25 : 9, 142, $seed);
        $quotient = $this->number(12, 30 + ($difficulty * 30), 143, $seed);
        $dividend = $divisor * $quotient;
        if ($difficulty >= 3 && $this->number(0, 1, 191, $seed) === 1) {
            $remainder = $this->number(1, $divisor - 1, 192, $seed);
            $dividend += $remainder;
            $answer = "{$quotient} R {$remainder}";

            return $this->choiceProblem(
                "Divide {$dividend} by {$divisor}. Which answer gives both the quotient and remainder?",
                [$answer, ($quotient + 1) . " R {$remainder}", "{$quotient} R " . max(0, $remainder - 1)],
                $answer,
                ['Find the greatest multiple of the divisor that does not exceed the dividend.', 'The remainder must be smaller than the divisor.'],
                "{$divisor} × {$quotient} + {$remainder} = {$dividend}, so the result is {$answer}."
            );
        }
        $prompt = $this->promptVariant([
            "Calculate {$dividend} ÷ {$divisor}.",
            "Share {$dividend} items equally among {$divisor} groups. How many items are in each group?",
            "How many groups of {$divisor} can be made from {$dividend}?",
            "Find the missing factor: {$divisor} × __ = {$dividend}.",
        ], $seed, 262);

        return $this->numberProblem(
            $prompt,
            $quotient,
            ["Find how many groups of {$divisor} make {$dividend}.", 'Use multiplication to check the quotient.'],
            "{$divisor} × {$quotient} = {$dividend}, so the quotient is {$quotient}."
        );
    }

    private function estimateQuotient(int $difficulty, ?int $seed): array
    {
        $divisor = $this->number(2, 5 + $difficulty, 144, $seed) * 10;
        $quotient = $this->number(2, 5 + $difficulty, 145, $seed);
        $compatible = $divisor * $quotient;
        $offsetLimit = max(5, (int) floor($divisor * 0.4));
        $offset = $this->number(-$offsetLimit, $offsetLimit, 146, $seed);
        if ($offset === 0) {
            $offset = max(1, (int) floor($offsetLimit / 2));
        }
        $nearbyDividend = $compatible + $offset;
        $prompt = $this->promptVariant([
            "What is the best whole-number estimate for {$nearbyDividend} ÷ {$divisor}? Choose a nearby compatible dividend yourself.",
            "A shipment of about {$nearbyDividend} items is split into groups of {$divisor}. About how many groups will there be?",
            "Without calculating an exact decimal, estimate the quotient {$nearbyDividend} ÷ {$divisor}.",
        ], $seed, 263);

        return $this->choiceProblem(
            $prompt,
            [(string) $quotient, (string) max(1, $quotient - 1), (string) ($quotient + 1)],
            (string) $quotient,
            ["Look for the closest multiple of {$divisor} to {$nearbyDividend}.", "{$compatible} is nearby and divides evenly by {$divisor}."],
            "The useful compatible number is {$compatible}, and {$compatible} ÷ {$divisor} = {$quotient}."
        );
    }

    private function largeAddSubtract(int $difficulty, ?int $seed): array
    {
        $maximum = 200000 * $difficulty;
        if ($difficulty >= 4 && $this->number(0, 1, 193, $seed) === 1) {
            $start = $this->number(100000, max(100000, $maximum - 100000), 194, $seed);
            $removed = $this->number(10000, max(10000, (int) floor($start / 3)), 195, $seed);
            $added = $this->number(10000, max(10000, min(150000, $maximum - ($start - $removed))), 196, $seed);
            $answer = $start - $removed + $added;

            return $this->numberProblem(
                "A distribution center began with {$start} units, shipped {$removed}, then received {$added} new units. What is the final inventory?",
                $answer,
                ['Subtract the shipment from the starting inventory.', 'Add the new delivery to the remaining amount.'],
                "{$start} − {$removed} + {$added} = {$answer}."
            );
        }
        $addition = $this->number(0, 1, 149, $seed) === 1;
        if ($addition) {
            $a = $this->number(1000, $maximum - 500, 147, $seed);
            $b = $this->number(500, $maximum - $a, 148, $seed);
            $prompt = $this->promptVariant([
                "Calculate {$a} + {$b}.",
                "Two communities recorded {$a} and {$b} residents. What is their combined population?",
                "Complete the large-number sum: {$a} + {$b} = __.",
            ], $seed, 264);

            return $this->numberProblem(
                $prompt,
                $a + $b,
                ['Align equal place values.', 'Add from right to left and regroup when needed.'],
                "{$a} + {$b} = " . ($a + $b) . '.'
            );
        }

        $a = $this->number(1000, $maximum, 147, $seed);
        $b = $this->number(500, max(500, $a - 1), 148, $seed);
        $prompt = $this->promptVariant([
            "Calculate {$a} − {$b}.",
            "A storage center began with {$a} units and shipped {$b}. How many units remain?",
            "Complete the large-number difference: {$a} − {$b} = __.",
        ], $seed, 265);

        return $this->numberProblem(
            $prompt,
            $a - $b,
            ['Align equal place values.', 'Subtract from right to left and regroup when needed.'],
            "{$a} − {$b} = " . ($a - $b) . '.'
        );
    }

    private function numberSentence(int $difficulty, ?int $seed): array
    {
        $a = $this->number(2, 8 + $difficulty, 150, $seed);
        $b = $this->number(2, 8 + $difficulty, 151, $seed);
        $c = $this->number(1, min($a + $b - 1, 6 + $difficulty), 152, $seed);
        $answer = $a + $b - $c;
        $prompt = $this->promptVariant([
            "Complete the equivalent number sentence: {$a} + {$b} = {$c} + ?",
            "What number makes both sides equal: {$a} + {$b} = {$c} + __?",
            "Balance the equation by filling the box: {$c} + □ = {$a} + {$b}.",
        ], $seed, 266);

        return $this->numberProblem(
            $prompt,
            $answer,
            ["First find {$a} + {$b}.", "Subtract {$c} from that total."],
            "Both sides equal " . ($a + $b) . ", so the missing number is {$answer}."
        );
    }

    private function fractionDecimalGmdas(int $difficulty, ?int $seed): array
    {
        $factor = $this->number(2, 4 + $difficulty, 153, $seed);
        [$fraction, $fractionTenths] = [
            ['1/2', 5],
            ['1/5', 2],
            ['3/5', 6],
            ['4/5', 8],
        ][$this->number(0, 3, 267, $seed)];
        $decimalTenths = $this->number(5, 9, 268, $seed);
        $decimal = $this->formatNumber($decimalTenths / 10, 1);
        $productTenths = ($fractionTenths + $decimalTenths) * $factor;
        $subtrahend = $this->number(1, max(1, min(8, (int) floor($productTenths / 10))), 269, $seed);
        $answer = $this->formatNumber(($productTenths - ($subtrahend * 10)) / 10, 2);
        $prompt = $this->promptVariant([
            "Apply GMDAS: ({$fraction} + {$decimal}) × {$factor} − {$subtrahend} = ?",
            "Evaluate ({$fraction} + {$decimal}) × {$factor} − {$subtrahend}, completing the parentheses first.",
            "A calculation adds {$fraction} and {$decimal}, multiplies that sum by {$factor}, then subtracts {$subtrahend}. What is the result?",
        ], $seed, 268);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Convert the fraction to a terminating decimal before adding inside the parentheses.', 'Multiply the grouped sum, then perform the final subtraction.'],
            "{$fraction} + {$decimal} = " . $this->formatNumber(($fractionTenths + $decimalTenths) / 10, 1)
                . "; after multiplying by {$factor} and subtracting {$subtrahend}, the result is {$answer}."
        );
    }

    private function decimalPlaceValue(int $places, int $difficulty, ?int $seed): array
    {
        $places = max(1, min(4, $places));
        $scale = 10 ** $places;
        $fractionalDigits = $this->number(1, $scale - 1, 154, $seed);
        $whole = $this->number(1, 5 + ($difficulty * 10), 155, $seed);
        $valueScaled = ($whole * $scale) + $fractionalDigits;
        $value = $this->formatNumber($valueScaled / $scale, $places);
        $targetPlace = 10 ** $this->number(1, $places, 155, $seed);
        $digit = ((int) floor(($fractionalDigits / $scale) * $targetPlace)) % 10;
        if ($digit === 0) {
            $digit = $this->number(1, 9, 156, $seed);
            $placeStep = (int) ($scale / $targetPlace);
            $fractionalDigits += $digit * $placeStep;
            $fractionalDigits %= $scale;
            $valueScaled = ($whole * $scale) + $fractionalDigits;
            $value = $this->formatNumber($valueScaled / $scale, $places);
            $digit = ((int) floor(($fractionalDigits / $scale) * $targetPlace)) % 10;
        }
        $answer = $this->formatNumber($digit / $targetPlace, $places);
        $placeName = match ($targetPlace) {
            10 => 'tenths',
            100 => 'hundredths',
            1000 => 'thousandths',
            default => 'ten-thousandths',
        };

        $variant = $this->number(0, 3, 269, $seed);
        if ($variant === 1) {
            return $this->numberProblem(
                "Which digit in {$value} contributes the value {$answer}?",
                $digit,
                ['Match the value to its decimal place.', "A digit in the {$placeName} place is divided by {$targetPlace}."],
                "The digit {$digit} contributes {$answer}."
            );
        }

        if ($variant === 2) {
            $remaining = $this->formatNumber(($valueScaled / $scale) - ($digit / $targetPlace), $places);

            return $this->numberProblem(
                "Complete this decimal decomposition: {$value} = {$remaining} + __.",
                $answer,
                ['Compare the two decimal values place by place.', "The missing contribution comes from the {$placeName} digit."],
                "{$remaining} + {$answer} = {$value}."
            );
        }

        if ($variant === 3) {
            $unitValue = $this->formatNumber(1 / $targetPlace, $places);

            return $this->numberProblem(
                "In {$value}, the {$placeName} digit increases by 1 while all other digits stay fixed. By how much does the number increase?",
                $unitValue,
                ["One unit in the {$placeName} place equals 1/{$targetPlace}.", 'The other place values do not change.'],
                "Increasing that digit by 1 raises the number by {$unitValue}."
            );
        }

        return $this->numberProblem(
            "What value is contributed by the {$placeName} digit in {$value}?",
            $answer,
            ["The {$placeName} place has value 1/{$targetPlace}.", "Multiply {$digit} by 1/{$targetPlace}."],
            "The digit {$digit} is worth {$answer}."
        );
    }

    private function decimalFractionConversion(int $places, ?int $seed): array
    {
        $denominator = $places >= 2 && $this->number(0, 1, 156, $seed) === 1 ? 100 : 10;
        $numerator = $this->number(1, $denominator - 1, 157, $seed);
        $answer = $this->formatNumber($numerator / $denominator, $places);
        $prompt = $this->promptVariant([
            "Convert {$numerator}/{$denominator} to a decimal.",
            "Write the fraction {$numerator}/{$denominator} in decimal notation.",
            "Complete the equivalence: {$numerator}/{$denominator} = __ as a decimal.",
        ], $seed, 269);

        return $this->numberProblem(
            $prompt,
            $answer,
            ["The denominator {$denominator} names the decimal place.", "Divide {$numerator} by {$denominator}."],
            "{$numerator} ÷ {$denominator} = {$answer}."
        );
    }

    private function decimalCompareConvert(int $places, ?int $seed): array
    {
        $variant = $this->number(0, 2, 178, $seed);
        if ($variant === 0) {
            return $this->decimalFractionConversion($places, $seed);
        }

        $scale = 10 ** max(2, min(3, $places));
        $firstScaled = $this->number(1, $scale - 2, 179, $seed);
        $secondScaled = $this->number(1, $scale - 1, 180, $seed);
        if ($secondScaled === $firstScaled) {
            $secondScaled++;
        }
        $first = $this->formatNumber($firstScaled / $scale, $places);
        $second = $this->formatNumber($secondScaled / $scale, $places);

        if ($variant === 1) {
            $answer = $firstScaled < $secondScaled ? '<' : '>';

            return $this->choiceProblem(
                "Choose the symbol that makes this true: {$first} __ {$second}",
                ['<', '=', '>'],
                $answer,
                ['Align the decimal points.', 'Compare digits from left to right at equal place values.'],
                "{$first} {$answer} {$second}."
            );
        }

        $answer = $this->formatNumber(round($firstScaled / $scale, 1), 1);

        return $this->numberProblem(
            "Round {$first} to the nearest tenth.",
            $answer,
            ['Look at the hundredths digit.', 'Round the tenths digit up when the hundredths digit is 5 or more.'],
            "{$first} rounds to {$answer} to the nearest tenth."
        );
    }

    private function decimalAddSubtract(int $places, int $difficulty, ?int $seed): array
    {
        $scale = 10 ** max(1, min(4, $places));
        $minimumScaled = $difficulty >= 3 ? $scale : max(10, (int) ($scale / 10));
        $maximumScaled = (5 + ($difficulty * 8)) * $scale;
        $aScaled = $this->number($minimumScaled, $maximumScaled, 158, $seed);
        $bScaled = $this->number(1, $aScaled, 159, $seed);
        $a = $this->formatNumber($aScaled / $scale, $places);
        $b = $this->formatNumber($bScaled / $scale, $places);

        if ($difficulty >= 4) {
            $thirdScaled = $this->number(1, max(1, $aScaled + $bScaled - 1), 160, $seed);
            $third = $this->formatNumber($thirdScaled / $scale, $places);
            $answer = $this->formatNumber(($aScaled + $bScaled - $thirdScaled) / $scale, $places);
            $prompt = $this->promptVariant([
                "Calculate {$a} + {$b} − {$third}.",
                "A tank contains {$a} L, receives {$b} L, then releases {$third} L. How many liters remain?",
                "A balance changes by +{$a}, +{$b}, and −{$third}. What is its final value?",
            ], $seed, 270);

            return $this->numberProblem(
                $prompt,
                $answer,
                ['Align all decimal points.', 'Add the first two amounts, then subtract the third.'],
                "{$a} + {$b} − {$third} = {$answer}."
            );
        }

        $addition = $this->number(0, 1, 160, $seed) === 1;
        $answer = $this->formatNumber(($addition ? $aScaled + $bScaled : $aScaled - $bScaled) / $scale, $places);
        $symbol = $addition ? '+' : '−';
        $prompt = $addition
            ? $this->promptVariant([
                "Calculate {$a} + {$b}.",
                "Two measured lengths are {$a} m and {$b} m. What is their combined length?",
                "Complete the decimal sum: {$a} + {$b} = __.",
            ], $seed, 270)
            : $this->promptVariant([
                "Calculate {$a} − {$b}.",
                "A container held {$a} L and used {$b} L. How many liters remain?",
                "Complete the decimal difference: {$a} − {$b} = __.",
            ], $seed, 271);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Align the decimal points.', ($addition ? 'Add' : 'Subtract') . ' each place-value column.'],
            "{$a} {$symbol} {$b} = {$answer}."
        );
    }

    private function decimalMultiplication(int $places, int $difficulty, ?int $seed): array
    {
        $tenths = $this->number(11, 30 + ($difficulty * 15), 161, $seed);
        $factor = $this->number(2, 4 + $difficulty, 162, $seed);
        $a = $this->formatNumber($tenths / 10, min(2, $places));
        $productTenths = $tenths * $factor;
        $adjustmentTenths = $difficulty >= 4 ? $this->number(1, min(20, $productTenths - 1), 163, $seed) : 0;
        $adjustment = $this->formatNumber($adjustmentTenths / 10, min(2, $places));
        $answer = $this->formatNumber(($productTenths - $adjustmentTenths) / 10, min(2, $places));
        $prompt = $this->promptVariant([
            $adjustmentTenths > 0 ? "Calculate {$a} × {$factor} − {$adjustment}." : "Calculate {$a} × {$factor}.",
            $adjustmentTenths > 0
                ? "Each of {$factor} lengths measures {$a} m. After {$adjustment} m is trimmed from the combined length, how many meters remain?"
                : "Each of {$factor} equal lengths measures {$a} m. What is their total length?",
            $adjustmentTenths > 0
                ? "Find {$factor} groups of {$a}, then subtract {$adjustment}."
                : "Complete the decimal product: {$factor} groups of {$a} = __.",
        ], $seed, 272);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Multiply as whole numbers first.', $adjustmentTenths > 0 ? 'Place the decimal, then subtract the adjustment.' : 'Place the decimal so the product has the correct place value.'],
            $adjustmentTenths > 0
                ? "{$a} × {$factor} − {$adjustment} = {$answer}."
                : "{$a} × {$factor} = {$answer}."
        );
    }

    private function decimalDivision(int $difficulty, ?int $seed): array
    {
        $divisor = $this->number(2, 4 + $difficulty, 163, $seed);
        $answerTenths = $this->number(5, 20 + ($difficulty * 10), 164, $seed);
        $dividendTenths = $divisor * $answerTenths;
        $dividend = $this->formatNumber($dividendTenths / 10, 2);
        $answer = $this->formatNumber($answerTenths / 10, 2);
        $prompt = $this->promptVariant([
            "Calculate {$dividend} ÷ {$divisor}.",
            "Share {$dividend} liters equally among {$divisor} containers. How many liters go in each?",
            "Complete the decimal quotient: {$dividend} ÷ {$divisor} = __.",
        ], $seed, 273);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Divide as with whole numbers while keeping decimal place value.', "Check by multiplying {$answer} × {$divisor}."],
            "{$dividend} ÷ {$divisor} = {$answer}."
        );
    }

    private function fourDecimalOperations(int $difficulty, ?int $seed): array
    {
        if ($difficulty >= 3) {
            $places = min(4, $difficulty - 1);
            $scale = 10 ** $places;
            $aScaled = $this->number(
                12 * (int) ($scale / 10),
                (4 + $difficulty) * $scale,
                165,
                $seed
            );
            $bScaled = $this->number(
                (int) ($scale / 2),
                (int) (((4 + $difficulty) * $scale) / 2),
                166,
                $seed
            );
            if ($aScaled % 10 === 0) {
                $aScaled++;
            }
            if ($bScaled % 10 === 0) {
                $bScaled++;
            }
            $factor = $this->number(2, 3 + $difficulty, 167, $seed);
            $subtractScaled = $this->number(
                (int) ($scale / 10),
                min(3 * $scale, (($aScaled + $bScaled) * $factor) - 1),
                168,
                $seed
            );
            if ($subtractScaled % 10 === 0) {
                $subtractScaled++;
            }
            $a = $this->formatNumber($aScaled / $scale, $places);
            $b = $this->formatNumber($bScaled / $scale, $places);
            $subtract = $this->formatNumber($subtractScaled / $scale, $places);
            $answer = $this->formatNumber(
                ((($aScaled + $bScaled) * $factor) - $subtractScaled) / $scale,
                $places
            );

            $prompt = $this->promptVariant([
                "Evaluate ({$a} + {$b}) × {$factor} − {$subtract}.",
                "Apply the order of operations to ({$a} + {$b}) × {$factor} − {$subtract}.",
                "A calculation adds {$a} and {$b}, multiplies by {$factor}, then subtracts {$subtract}. What is the result?",
            ], $seed, 274);

            return $this->numberProblem(
                $prompt,
                $answer,
                ['Complete the decimal addition inside parentheses.', 'Multiply next, then perform the final subtraction.'],
                "({$a} + {$b}) × {$factor} − {$subtract} = {$answer}."
            );
        }

        $kind = ['add', 'subtract', 'multiply', 'divide'][$this->number(0, 3, 165, $seed)];

        return match ($kind) {
            'add', 'subtract' => $this->decimalAddSubtract(4, $difficulty, $seed),
            'multiply' => $this->decimalMultiplication(2, $difficulty, $seed),
            default => $this->decimalDivision($difficulty, $seed),
        };
    }

    private function factorMultipleProblem(int $difficulty, ?int $seed): array
    {
        $a = $this->number(3, 5 + $difficulty, 166, $seed);
        $b = $this->number(3, 5 + $difficulty, 167, $seed);
        $product = $a * $b;
        $variantMinimum = $difficulty >= 5 ? 2 : 0;
        $variant = $this->number($variantMinimum, min(3, $difficulty), 275, $seed);

        if ($variant === 1) {
            $other = $a * ($b + 1);
            $common = $a;

            return $this->choiceProblem(
                "Which number is a common factor of both {$product} and {$other}?",
                [(string) $common, (string) ($common + 1), (string) ($product + 1)],
                (string) $common,
                ['A common factor must divide both numbers evenly.', 'Test each choice against both values.'],
                "{$product} = {$a} × {$b} and {$other} = {$a} × " . ($b + 1) . ", so {$a} is common to both."
            );
        }

        if ($variant === 2) {
            $start = ($product * $this->number(2, 4, 168, $seed)) + $this->number(1, max(1, $product - 1), 169, $seed);
            $answer = (int) (ceil($start / $product) * $product);

            return $this->numberProblem(
                "What is the first multiple of {$product} that is greater than {$start}?",
                $answer,
                ["List multiples of {$product} around {$start}.", 'Choose the first one that passes the boundary, not merely the closest one.'],
                "The first multiple of {$product} greater than {$start} is {$answer}."
            );
        }

        if ($variant === 3) {
            $numerator = $a * $this->number(2, 4 + $difficulty, 170, $seed);
            $denominator = $a * $this->number(5, 8 + $difficulty, 171, $seed);
            $answer = $this->fractionLabel($numerator, $denominator);

            return $this->choiceProblem(
                "Use common factors to reduce {$numerator}/{$denominator} to simplest form.",
                $this->fractionOptions($numerator, $denominator),
                $answer,
                ['Find a factor shared by the numerator and denominator.', 'Divide both parts by their greatest common factor.'],
                "Reducing {$numerator}/{$denominator} gives {$answer}."
            );
        }

        $wrongOptions = [];
        $candidate = $this->number(2, max(3, $product - 1), 172, $seed);
        while (count($wrongOptions) < 2) {
            if ($product % $candidate !== 0 && !in_array((string) $candidate, $wrongOptions, true)) {
                $wrongOptions[] = (string) $candidate;
            }
            $candidate++;
            if ($candidate >= $product) {
                $candidate = 2;
            }
        }

        return $this->choiceProblem(
            "Only one choice divides {$product} with no remainder. Which number is it?",
            array_merge([(string) $a], $wrongOptions),
            (string) $a,
            ['Test each candidate by division.', 'A factor leaves a remainder of zero.'],
            "{$product} ÷ {$a} = {$b}, so {$a} is the factor."
        );
    }

    private function divisibilityProblem(int $difficulty, ?int $seed): array
    {
        $divisors = [2, 3, 4, 5, 6, 8, 9, 10, 11, 12];
        $divisor = $divisors[$this->number(0, min(count($divisors) - 1, 3 + $difficulty), 168, $seed)];
        $multiple = $this->number(2, 8 + $difficulty, 169, $seed);
        $multipleValue = $divisor * $multiple;

        if ($difficulty >= 3) {
            $variant = $this->number(0, 2, 276, $seed);
            $remainder = $this->number(1, $divisor - 1, 277, $seed);
            $nearValue = $multipleValue + $remainder;

            if ($variant === 1) {
                $answer = $divisor - $remainder;
                $prompt = $this->promptVariant([
                    "A teacher has {$nearValue} counters. What is the fewest number of counters to add so they can be arranged in equal groups of {$divisor} with none left over?",
                    "The number {$nearValue} is not divisible by {$divisor}. What smallest positive amount makes the new total divisible by {$divisor}?",
                    "A shelf holds {$nearValue} books. How many more books are needed to fill complete stacks of {$divisor} without a remainder?",
                ], $seed, 278);

                return $this->numberProblem(
                    $prompt,
                    $answer,
                    ["Find the remainder when {$nearValue} is divided by {$divisor}.", 'Add only enough to complete the next full group.'],
                    "{$nearValue} leaves remainder {$remainder}; {$remainder} + {$answer} = {$divisor}, so adding {$answer} reaches " . ($nearValue + $answer) . '.'
                );
            }

            if ($variant === 2) {
                $secondMultiple = $multipleValue + $divisor;
                $answer = (string) $nearValue;
                $prompt = $this->promptVariant([
                    "Two of these numbers are divisible by {$divisor}. Which one is the exception?",
                    "Which value cannot be separated into equal groups of {$divisor} without a remainder?",
                    "A learner says all three choices are multiples of {$divisor}. Select the counterexample.",
                ], $seed, 279);

                return $this->choiceProblem(
                    $prompt,
                    [(string) $multipleValue, (string) $secondMultiple, (string) $nearValue],
                    $answer,
                    ['Apply the divisibility rule to every choice.', 'The exception leaves a nonzero remainder.'],
                    "{$multipleValue} and {$secondMultiple} are multiples of {$divisor}, but {$nearValue} leaves remainder {$remainder}."
                );
            }

            $otherRemainder = $divisor === 2
                ? 1
                : (($remainder % ($divisor - 1)) + 1);
            $secondNearValue = $multipleValue + $divisor + $otherRemainder;
            $answer = (string) $multipleValue;
            $prompt = $this->promptVariant([
                "Only one choice is divisible by {$divisor}. Which value is it?",
                "Which collection can be split into equal groups of {$divisor} with nothing left over?",
                "Apply the divisibility rule for {$divisor} to select the one exact multiple.",
            ], $seed, 280);

            return $this->choiceProblem(
                $prompt,
                [(string) $multipleValue, (string) $nearValue, (string) $secondNearValue],
                $answer,
                ['Test all three values with the divisibility rule.', 'Reject any choice that leaves a remainder.'],
                "{$multipleValue} = {$divisor} × {$multiple}, while the other choices leave remainders."
            );
        }

        $isDivisible = $this->number(0, 1, 276, $seed) === 1;
        $remainder = $isDivisible ? 0 : $this->number(1, $divisor - 1, 277, $seed);
        $value = ($divisor * $multiple) + $remainder;
        $answer = $isDivisible ? 'Yes' : 'No';
        $prompt = $this->promptVariant([
            "Is {$value} divisible by {$divisor}?",
            "Will {$value} ÷ {$divisor} produce a whole-number quotient?",
            "Can {$value} objects be separated into equal groups of {$divisor} with none left over?",
        ], $seed, 278);

        return $this->choiceProblem(
            $prompt,
            ['Yes', 'No', 'Cannot be determined'],
            $answer,
            ['Apply the divisibility rule for the divisor.', 'A number is divisible when the quotient is a whole number.'],
            $isDivisible
                ? "Yes. {$value} ÷ {$divisor} = {$multiple} with no remainder."
                : "No. {$value} ÷ {$divisor} leaves a remainder of {$remainder}."
        );
    }

    private function primeCompositeProblem(int $difficulty, ?int $seed): array
    {
        $primes = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47];
        $composites = [4, 6, 8, 9, 10, 12, 14, 15, 16, 18, 20, 21, 22, 24, 25, 26, 27, 28];

        if ($difficulty >= 3) {
            $variant = $this->number(0, 2, 281, $seed);
            $primeIndex = $this->number(2, min(count($primes) - 1, 4 + ($difficulty * 2)), 282, $seed);
            $compositeIndex = $this->number(2, min(count($composites) - 1, 4 + ($difficulty * 2)), 283, $seed);
            $primeValue = $primes[$primeIndex];
            $compositeValue = $composites[$compositeIndex];

            if ($variant === 0) {
                $otherComposite = $composites[($compositeIndex + 3) % count($composites)];

                return $this->choiceProblem(
                    $this->promptVariant([
                        'Which choice has exactly two positive factors?',
                        'Only one of these numbers is prime. Which one?',
                        'Select the number that cannot be arranged as a rectangle with both side lengths greater than 1.',
                    ], $seed, 284),
                    [(string) $primeValue, (string) $compositeValue, (string) $otherComposite],
                    (string) $primeValue,
                    ['Check possible factors up to the square root of each number.', 'A prime has only 1 and itself as positive factors.'],
                    "{$primeValue} has exactly the factors 1 and {$primeValue}; the other choices have additional factor pairs."
                );
            }

            if ($variant === 1) {
                $otherPrime = $primes[($primeIndex + 2) % count($primes)];

                return $this->choiceProblem(
                    $this->promptVariant([
                        'Which choice is composite while the other two are prime?',
                        'Select the number that has a factor other than 1 and itself.',
                        'One number below can form a rectangular array with both side lengths greater than 1. Which one?',
                    ], $seed, 285),
                    [(string) $compositeValue, (string) $primeValue, (string) $otherPrime],
                    (string) $compositeValue,
                    ['Try small divisors on each choice.', 'One nontrivial factor pair is enough to prove a number composite.'],
                    "{$compositeValue} has more than two positive factors, while {$primeValue} and {$otherPrime} are prime."
                );
            }

            $leftFactor = $this->number(2, 3 + $difficulty, 286, $seed);
            $rightFactor = $this->number(2, 4 + $difficulty, 287, $seed);
            $value = $leftFactor * $rightFactor;
            $answer = "{$leftFactor} × {$rightFactor}";

            return $this->choiceProblem(
                $this->promptVariant([
                    "Which factor pair proves that {$value} is composite?",
                    "A learner claims {$value} is prime. Which evidence disproves the claim?",
                    "Select a factorization of {$value} that uses neither 1 nor {$value}.",
                ], $seed, 288),
                [
                    $answer,
                    "1 × {$value}",
                    "{$leftFactor} × " . ($rightFactor + 1),
                ],
                $answer,
                ['A composite number has a factor pair besides 1 and itself.', 'Check which multiplication actually equals the given number.'],
                "{$leftFactor} × {$rightFactor} = {$value}, and both factors are greater than 1, so {$value} is composite."
            );
        }

        $prime = $this->number(0, 1, 170, $seed) === 1;
        $pool = $prime ? $primes : $composites;
        $value = $pool[$this->number(0, min(count($pool) - 1, 2 + $difficulty), 171, $seed)];
        $answer = $prime ? 'Prime' : 'Composite';
        $prompt = $this->promptVariant([
            "Is {$value} prime or composite?",
            "Classify {$value} by the number of positive factors it has.",
            "Does {$value} have exactly two factors, or more than two?",
        ], $seed, 279);

        return $this->choiceProblem(
            $prompt,
            ['Prime', 'Composite', 'Neither'],
            $answer,
            ['List the positive factors.', 'A prime has exactly two factors; a composite has more than two.'],
            "{$value} is {$answer}."
        );
    }

    private function gcfLcmProblem(int $difficulty, ?int $seed): array
    {
        $common = $this->number(2, 3 + $difficulty, 172, $seed);
        $aFactor = $this->number(2, 4 + $difficulty, 173, $seed);
        $bFactor = $this->number(2, 4 + $difficulty, 174, $seed);
        while ($this->gcd($aFactor, $bFactor) !== 1) {
            $bFactor++;
        }
        $a = $common * $aFactor;
        $b = $common * $bFactor;
        $askGcf = $this->number(0, 1, 175, $seed) === 1;
        $answer = $askGcf ? $this->gcd($a, $b) : (int) (($a * $b) / $this->gcd($a, $b));
        $label = $askGcf ? 'greatest common factor' : 'least common multiple';
        $prompt = $this->promptVariant([
            "Find the {$label} of {$a} and {$b}.",
            "What is the {$label} shared by {$a} and {$b}?",
            "Use factor or multiple lists to determine the {$label} for {$a} and {$b}.",
        ], $seed, 280);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['List factors or multiples systematically.', $askGcf ? 'Choose the largest shared factor.' : 'Choose the first shared positive multiple.'],
            "The {$label} of {$a} and {$b} is {$answer}."
        );
    }

    private function compositeCircleArea(int $radius, ?int $seed): array
    {
        $squareSide = $radius * 2;
        $squareArea = $squareSide * $squareSide;
        $semicircleArea = (3.14 * $radius * $radius) / 2;
        $answer = $this->formatNumber($squareArea + $semicircleArea);
        $prompt = $this->promptVariant([
            "Use π = 3.14. A composite figure is a {$squareSide} cm square plus a semicircle of radius {$radius} cm. What is its total area?",
            "A floor plan joins a {$squareSide} cm by {$squareSide} cm square to a semicircle of radius {$radius} cm. Using π = 3.14, find the combined area.",
            "Add the area of a square with side {$squareSide} cm and half the area of a radius-{$radius} cm circle. Use π = 3.14.",
        ], $seed, 248);

        return $this->numberProblem(
            $prompt,
            $answer,
            ['Find the square area and half of the circle area.', 'Add the two non-overlapping areas.'],
            "{$squareArea} + " . $this->formatNumber($semicircleArea) . " = {$answer} cm²."
        );
    }

    private function formatNumber(float $value, int $places = 4): string
    {
        $formatted = rtrim(rtrim(number_format($value, $places, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function ordinal(int $number): string
    {
        $mod100 = $number % 100;
        $suffix = in_array($mod100, [11, 12, 13], true)
            ? 'th'
            : match ($number % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };

        return $number . $suffix;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }

    private function fractionLabel(int $numerator, int $denominator): string
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('A fraction denominator cannot be zero.');
        }
        if ($numerator === 0) {
            return '0';
        }
        if ($denominator < 0) {
            $numerator *= -1;
            $denominator *= -1;
        }
        $divisor = $this->gcd(abs($numerator), $denominator);
        $numerator = (int) ($numerator / $divisor);
        $denominator = (int) ($denominator / $divisor);

        return $denominator === 1 ? (string) $numerator : "{$numerator}/{$denominator}";
    }

    private function fractionOptions(int $numerator, int $denominator): array
    {
        $answer = $this->fractionLabel($numerator, $denominator);
        $options = [$answer];
        $candidates = [
            [$numerator + 1, $denominator],
            [$numerator - 1, $denominator],
            [$numerator, $denominator + 1],
            [$denominator, max(1, abs($numerator))],
            [$numerator + $denominator, $denominator],
            [$numerator + 1, $denominator + 1],
        ];

        foreach ($candidates as [$candidateNumerator, $candidateDenominator]) {
            $candidate = $this->fractionLabel($candidateNumerator, $candidateDenominator);
            if (!in_array($candidate, $options, true)) {
                $options[] = $candidate;
            }
            if (count($options) === 4) {
                break;
            }
        }

        for ($offset = 2; count($options) < 4; $offset++) {
            $candidate = $this->fractionLabel($numerator + $offset, $denominator + 1);
            if (!in_array($candidate, $options, true)) {
                $options[] = $candidate;
            }
        }

        return $options;
    }

    private function clockLabel(int $totalMinutes): string
    {
        $minutesInHalfDay = 12 * 60;
        $normalized = (($totalMinutes % $minutesInHalfDay) + $minutesInHalfDay) % $minutesInHalfDay;
        $hour = (int) floor($normalized / 60);
        $minute = $normalized % 60;
        if ($hour === 0) {
            $hour = 12;
        }

        return sprintf('%d:%02d', $hour, $minute);
    }

    private function clockLabelWithMeridiem(int $totalMinutes): string
    {
        $minutesInDay = 24 * 60;
        $normalized = (($totalMinutes % $minutesInDay) + $minutesInDay) % $minutesInDay;
        $period = $normalized < 12 * 60 ? 'a.m.' : 'p.m.';

        return $this->clockLabel($normalized) . " {$period}";
    }

    private function twentyFourHourLabel(int $totalMinutes): string
    {
        $minutesInDay = 24 * 60;
        $normalized = (($totalMinutes % $minutesInDay) + $minutesInDay) % $minutesInDay;

        return sprintf('%02d:%02d', (int) floor($normalized / 60), $normalized % 60);
    }

    private function numberProblem(string $prompt, int|float|string $answer, array $hints, string $explanation): array
    {
        return [
            'prompt' => $prompt,
            'answer_type' => 'number',
            'options' => [],
            'correct_answer' => (string) $answer,
            'hints' => array_values($hints),
            'explanation' => $explanation,
        ];
    }

    private function choiceProblem(
        string $prompt,
        array $options,
        string $answer,
        array $hints,
        string $explanation
    ): array {
        $answer = (string) $answer;
        $options = array_values(array_unique(array_map(
            fn ($option): string => (string) $option,
            $options
        )));
        if (!in_array($answer, $options, true)) {
            array_unshift($options, $answer);
        }

        return [
            'prompt' => $prompt,
            'answer_type' => 'choice',
            'options' => array_values($options),
            'correct_answer' => $answer,
            'hints' => array_values($hints),
            'explanation' => $explanation,
        ];
    }

    private function promptVariant(array $prompts, ?int $seed, int $salt): string
    {
        return $prompts[$this->number(0, count($prompts) - 1, $salt, $seed)];
    }

    /**
     * Rotate choice positions without disguising duplicate questions behind
     * generic prefixes. Meaningful variation belongs in each topic generator.
     */
    private function varyPresentation(array $problem, ?int $seed): array
    {
        if ($problem['answer_type'] === 'choice' && count($problem['options']) > 1) {
            $shuffleSeed = $seed ?? random_int(1, PHP_INT_MAX);
            $ranked = [];
            foreach ($problem['options'] as $index => $option) {
                $ranked[] = [
                    'option' => $option,
                    'rank' => hash('sha256', "{$shuffleSeed}:902:{$index}:{$option}"),
                ];
            }
            usort($ranked, fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);
            $problem['options'] = array_column($ranked, 'option');
        }

        return $problem;
    }

    private function decimal(int $cents): string
    {
        return rtrim(rtrim(number_format($cents / 100, 2, '.', ''), '0'), '.');
    }

    private function number(int $minimum, int $maximum, int $salt, ?int $seed): int
    {
        if ($maximum < $minimum) {
            $maximum = $minimum;
        }

        if ($seed === null) {
            return random_int($minimum, $maximum);
        }

        // SHA-256 avoids the modulo correlations CRC32 produced between
        // neighboring seeds and salts. That correlation made several
        // supposedly independent variables repeat together.
        $unsigned = (int) hexdec(substr(hash('sha256', "{$seed}:{$salt}"), 0, 8));

        return $minimum + ($unsigned % (($maximum - $minimum) + 1));
    }
}
