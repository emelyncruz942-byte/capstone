<?php

namespace App\Support;

use InvalidArgumentException;

class PracticeCurriculum
{
    public const TERMS = ['First Term', 'Second Term', 'Third Term'];

    /**
     * Topic-level coverage from the April 17, 2026 Grade 1-6 General
     * Mathematics Three-Term Budget of Work. Closely related weekly
     * competencies are kept together so a learner can deliberately practice
     * one curriculum topic without turning the map into a list of outcomes.
     */
    public static function forGrade(int $grade): array
    {
        return match ($grade) {
            1 => [
                self::topic('g1-shapes-2d', 'Simple 2-Dimensional Shapes', 'First Term', 'Measurement and Geometry', 'Identify, compare, compose, and decompose triangles, squares, and rectangles.', 'geometry', 'fa-shapes', ['kind' => 'shapes-2d']),
                self::topic('g1-whole-numbers-100', 'Whole Numbers up to 100', 'First Term', 'Number and Algebra', 'Count, read, write, recognize, represent, compare, order, and compose numbers up to 100.', 'number-sense', 'fa-arrow-down-1-9', ['kind' => 'whole-numbers', 'limit' => 100]),
                self::topic('g1-ordinals-10', 'Ordinal Numbers up to 10th', 'First Term', 'Number and Algebra', 'Describe the position of objects using ordinal numbers from 1st to 10th.', 'number-sense', 'fa-ranking-star', ['kind' => 'ordinals', 'limit' => 10]),
                self::topic('g1-add-20', 'Addition with Sums up to 20', 'First Term', 'Number and Algebra', 'Model addition, use its basic properties, and solve one-step problems with sums up to 20.', 'addition', 'fa-plus', ['limit' => 20]),
                self::topic('g1-length-nonstandard', 'Length and Distance Using Non-Standard Units', 'First Term', 'Measurement and Geometry', 'Measure, compare, and solve problems about length and distance using informal units.', 'measurement', 'fa-ruler', ['kind' => 'length-nonstandard']),

                self::topic('g1-place-value', 'Place Value in 2-Digit Numbers', 'Second Term', 'Number and Algebra', 'Count by 2s, 5s, and 10s, then identify and decompose tens and ones.', 'place-value', 'fa-cubes-stacked', ['digits' => 2]),
                self::topic('g1-add-100', 'Addition with Sums up to 100', 'Second Term', 'Number and Algebra', 'Add 1- and 2-digit numbers without regrouping and solve related problems.', 'addition', 'fa-circle-plus', ['limit' => 100]),
                self::topic('g1-pictographs', 'Pictographs and Data Tables', 'Second Term', 'Data and Probability', 'Collect one-variable data, present it in a pictograph, and interpret or organize the results.', 'data', 'fa-chart-column', ['kind' => 'pictograph', 'scale' => 1]),
                self::topic('g1-subtract-100', 'Subtraction within 100', 'Second Term', 'Number and Algebra', 'Model taking away, find missing values, and subtract 1- and 2-digit numbers without regrouping.', 'subtraction', 'fa-minus', ['limit' => 100]),

                self::topic('g1-repeating-patterns', 'Repeating Patterns', 'Third Term', 'Number and Algebra', 'Find missing terms and create repeating patterns with objects, images, or numbers.', 'patterns', 'fa-repeat', ['kind' => 'repeating']),
                self::topic('g1-halves-quarters', 'Halves and Quarters', 'Third Term', 'Number and Algebra', 'Illustrate, compare, and count halves and quarters as parts of a whole.', 'fractions', 'fa-chart-pie', ['kind' => 'halves-quarters']),
                self::topic('g1-money-value-100', 'Philippine Money up to ₱100', 'Third Term', 'Number and Algebra', 'Recognize peso coins and bills, determine their total value, and compare denominations.', 'money', 'fa-coins', ['kind' => 'value', 'limit' => 100]),
                self::topic('g1-money-operations-100', 'Adding and Subtracting Money up to ₱100', 'Third Term', 'Number and Algebra', 'Solve one-step addition and subtraction problems involving Philippine money.', 'money', 'fa-money-bill-wave', ['kind' => 'operations', 'limit' => 100]),
                self::topic('g1-turns', 'Half and Quarter Turns', 'Third Term', 'Measurement and Geometry', 'Track positions after clockwise or counterclockwise half and quarter turns.', 'transformations', 'fa-rotate', ['kind' => 'turns']),
                self::topic('g1-time-calendar', 'Time and Calendars', 'Third Term', 'Measurement and Geometry', 'Read hours, half hours, and quarter hours, and work with days, weeks, months, and calendars.', 'time', 'fa-clock', ['kind' => 'clock-calendar']),
            ],
            2 => [
                self::topic('g2-circles-composite-shapes', 'Circles and Composite Shapes', 'First Term', 'Measurement and Geometry', 'Describe circles, half circles, and quarter circles, then compose and decompose figures.', 'geometry', 'fa-circle-half-stroke', ['kind' => 'circle-composites']),
                self::topic('g2-slides', 'Slides of Shapes and Figures', 'First Term', 'Measurement and Geometry', 'Describe and draw one-direction, multi-step slides or translations.', 'transformations', 'fa-arrows-left-right', ['kind' => 'translation-one-direction']),
                self::topic('g2-whole-numbers-1000', 'Whole Numbers up to 1,000', 'First Term', 'Number and Algebra', 'Count, read, write, represent, skip-count, order, and use place value with 3-digit numbers.', 'number-sense', 'fa-arrow-down-1-9', ['kind' => 'whole-numbers', 'limit' => 1000]),
                self::topic('g2-ordinals-20', 'Ordinal Numbers up to 20th', 'First Term', 'Number and Algebra', 'Describe positions using ordinal numbers from 1st to 20th.', 'number-sense', 'fa-ranking-star', ['kind' => 'ordinals', 'limit' => 20]),
                self::topic('g2-add-1000', 'Addition with Sums up to 1,000', 'First Term', 'Number and Algebra', 'Add with expanded form, regrouping, and addition properties, including word problems.', 'addition', 'fa-plus', ['limit' => 1000]),
                self::topic('g2-money-1000', 'Philippine Money up to ₱1,000', 'First Term', 'Number and Algebra', 'Determine and compare combinations of coins and bills and solve addition problems with money.', 'money', 'fa-coins', ['kind' => 'operations', 'limit' => 1000]),

                self::topic('g2-length-metric', 'Length and Distance in Meters and Centimeters', 'Second Term', 'Measurement and Geometry', 'Measure, compare, estimate, choose units, and solve problems involving length and distance.', 'measurement', 'fa-ruler-combined', ['kind' => 'length-metric']),
                self::topic('g2-subtract-1000', 'Subtraction within 1,000', 'Second Term', 'Number and Algebra', 'Subtract with and without regrouping and solve one- and two-step problems, including money.', 'subtraction', 'fa-minus', ['limit' => 1000]),
                self::topic('g2-increasing-decreasing-patterns', 'Increasing and Decreasing Patterns', 'Second Term', 'Number and Algebra', 'Find missing terms and create increasing or decreasing patterns.', 'patterns', 'fa-arrow-trend-up', ['kind' => 'increasing-decreasing']),
                self::topic('g2-scaled-pictographs', 'Pictographs with a Scale', 'Second Term', 'Data and Probability', 'Present and interpret tabular data using pictographs with or without a scale.', 'data', 'fa-chart-column', ['kind' => 'pictograph', 'scale' => 2]),

                self::topic('g2-equal-groups', 'Multiplication Tables for 2, 3, 4, 5, and 10', 'Third Term', 'Number and Algebra', 'Model repeated addition and equal groups, recall facts, and solve multiplication problems.', 'multiplication', 'fa-xmark', ['maximum' => 10, 'counter_limit' => 10, 'groups' => true, 'factors' => [2, 3, 4, 5, 10]]),
                self::topic('g2-division-tables', 'Division Using the 2, 3, 4, 5, and 10 Tables', 'Third Term', 'Number and Algebra', 'Model equal sharing and repeated subtraction, find missing values, and solve division problems.', 'division', 'fa-divide', ['maximum' => 10, 'counter_limit' => 10, 'factors' => [2, 3, 4, 5, 10]]),
                self::topic('g2-odd-even', 'Odd and Even Numbers', 'Third Term', 'Number and Algebra', 'Distinguish odd and even numbers using division by 2.', 'number-sense', 'fa-code-branch', ['kind' => 'odd-even', 'limit' => 1000]),
                self::topic('g2-fractions', 'Unit and Similar Fractions', 'Third Term', 'Number and Algebra', 'Represent, read, write, identify, and order fractions with denominators 2, 3, 4, 5, 6, and 8.', 'fractions', 'fa-chart-pie', ['kind' => 'unit-similar']),
                self::topic('g2-time-elapsed', 'Duration and Elapsed Time', 'Third Term', 'Measurement and Geometry', 'Use calendars and clocks, write a.m. and p.m. times, and solve elapsed-time problems.', 'time', 'fa-stopwatch', ['kind' => 'elapsed']),
                self::topic('g2-lines-surfaces', 'Lines and Surfaces', 'Third Term', 'Measurement and Geometry', 'Distinguish straight and curved lines and flat and curved surfaces.', 'geometry', 'fa-bezier-curve', ['kind' => 'lines-surfaces']),
                self::topic('g2-perimeter', 'Perimeter of Triangles, Squares, and Rectangles', 'Third Term', 'Measurement and Geometry', 'Measure, calculate, and solve problems involving the perimeter of plane figures.', 'geometry', 'fa-draw-polygon', ['kind' => 'perimeter']),
            ],
            3 => [
                self::topic('g3-area-rectangles', 'Area of Squares and Rectangles', 'First Term', 'Measurement and Geometry', 'Estimate, derive formulas for, calculate, and solve problems involving area.', 'geometry', 'fa-border-all', ['kind' => 'area-rectangles']),
                self::topic('g3-points-lines-rays', 'Points, Lines, Line Segments, and Rays', 'First Term', 'Measurement and Geometry', 'Recognize and draw points, lines, line segments, and rays.', 'geometry', 'fa-slash', ['kind' => 'line-basics']),
                self::topic('g3-line-relationships', 'Parallel, Perpendicular, and Intersecting Lines', 'First Term', 'Measurement and Geometry', 'Recognize and draw line relationships and equal-length segments.', 'geometry', 'fa-grip-lines', ['kind' => 'line-relationships']),
                self::topic('g3-whole-numbers-10000', 'Whole Numbers up to 10,000', 'First Term', 'Number and Algebra', 'Represent, read, write, place, round, compare, and order numbers up to 10,000.', 'number-sense', 'fa-arrow-down-1-9', ['kind' => 'whole-numbers', 'limit' => 10000]),
                self::topic('g3-ordinals-100', 'Ordinal Numbers up to 100th', 'First Term', 'Number and Algebra', 'Describe positions using ordinal numbers up to 100th.', 'number-sense', 'fa-ranking-star', ['kind' => 'ordinals', 'limit' => 100]),

                self::topic('g3-mass', 'Mass in Milligrams, Grams, and Kilograms', 'Second Term', 'Measurement and Geometry', 'Measure, estimate, and compare mass using appropriate tools and units.', 'measurement', 'fa-weight-scale', ['kind' => 'mass']),
                self::topic('g3-capacity', 'Capacity in Milliliters and Liters', 'Second Term', 'Measurement and Geometry', 'Measure, estimate, and compare the capacity of containers.', 'measurement', 'fa-glass-water', ['kind' => 'capacity']),
                self::topic('g3-add-10000', 'Addition up to 10,000, Including Money', 'Second Term', 'Number and Algebra', 'Add, estimate sums, and solve problems with up to 4-digit numbers and Philippine money.', 'addition', 'fa-plus', ['limit' => 10000]),
                self::topic('g3-subtract-10000', 'Subtraction up to 10,000, Including Money', 'Second Term', 'Number and Algebra', 'Subtract, estimate differences, and solve multi-step problems, including money.', 'subtraction', 'fa-minus', ['limit' => 10000]),
                self::topic('g3-bar-graphs', 'Tables and Single Bar Graphs', 'Second Term', 'Data and Probability', 'Collect, present, interpret, and solve problems with horizontal and vertical bar graphs.', 'data', 'fa-chart-simple', ['kind' => 'bar-graph']),
                self::topic('g3-likelihood', 'Likelihood of Outcomes', 'Second Term', 'Data and Probability', 'Compare events as certain, impossible, equally likely, or more or less likely.', 'data', 'fa-dice', ['kind' => 'likelihood']),

                self::topic('g3-multiply', 'Multiplication Tables for 6, 7, 8, and 9', 'Third Term', 'Number and Algebra', 'Recall multiplication facts for 6, 7, 8, and 9.', 'multiplication', 'fa-xmark', ['minimum' => 6, 'maximum' => 9, 'counter_limit' => 10]),
                self::topic('g3-multiplication-properties', 'Properties of Multiplication', 'Third Term', 'Number and Algebra', 'Apply identity, zero, commutative, associative, and distributive properties.', 'arithmetic', 'fa-diagram-project', ['kind' => 'multiplication-properties']),
                self::topic('g3-multi-digit-multiplication', 'Multiplication of Multi-Digit Numbers', 'Third Term', 'Number and Algebra', 'Multiply with or without regrouping and solve one- and two-step problems.', 'arithmetic', 'fa-calculator', ['kind' => 'multi-digit-multiplication']),
                self::topic('g3-estimate-products', 'Estimating Products', 'Third Term', 'Number and Algebra', 'Estimate products by rounding factors to useful multiples of 10.', 'arithmetic', 'fa-bullseye', ['kind' => 'estimate-products']),
                self::topic('g3-combined-patterns', 'Repeating and Increasing or Decreasing Patterns', 'Third Term', 'Number and Algebra', 'Find missing terms and explain rules in combined patterns.', 'patterns', 'fa-wave-square', ['kind' => 'combined']),
                self::topic('g3-divide', 'Division Using the 6, 7, 8, and 9 Tables', 'Third Term', 'Number and Algebra', 'Use inverse multiplication facts and equal jumps to divide.', 'division', 'fa-divide', ['minimum' => 6, 'maximum' => 9, 'counter_limit' => 10]),
                self::topic('g3-multi-digit-division', 'Division of 2- to 4-Digit Numbers', 'Third Term', 'Number and Algebra', 'Divide with and without remainders, including division by 10, 100, and 1,000.', 'arithmetic', 'fa-table-cells', ['kind' => 'multi-digit-division']),
                self::topic('g3-estimate-quotients', 'Estimating Quotients', 'Third Term', 'Number and Algebra', 'Estimate quotients using nearby multiples of 10 or 100.', 'arithmetic', 'fa-bullseye', ['kind' => 'estimate-quotients']),
                self::topic('g3-similar-fractions', 'Fractions Equal to or Greater Than One', 'Third Term', 'Number and Algebra', 'Represent fractions equal to or greater than one and add or subtract similar fractions.', 'fractions', 'fa-chart-pie', ['kind' => 'similar-add-sub']),
                self::topic('g3-translation', 'Translations of Shapes and Figures', 'Third Term', 'Measurement and Geometry', 'Describe and draw two-direction, multi-step slides.', 'transformations', 'fa-up-down-left-right', ['kind' => 'translation-two-direction']),
                self::topic('g3-line-symmetry', 'Line Symmetry', 'Third Term', 'Measurement and Geometry', 'Identify lines of symmetry and complete symmetric figures.', 'geometry', 'fa-arrows-left-right-to-line', ['kind' => 'symmetry']),
            ],
            4 => [
                self::topic('g4-angles', 'Measuring and Classifying Angles', 'First Term', 'Measurement and Geometry', 'Illustrate, measure, draw, and classify acute, right, and obtuse angles.', 'geometry', 'fa-compass-drafting', ['kind' => 'angles']),
                self::topic('g4-triangles-quadrilaterals', 'Triangles and Quadrilaterals', 'First Term', 'Measurement and Geometry', 'Draw, describe, classify, and differentiate triangles and quadrilaterals.', 'geometry', 'fa-shapes', ['kind' => 'shape-properties']),
                self::topic('g4-composite-perimeter', 'Perimeter of Quadrilaterals and Composite Figures', 'First Term', 'Measurement and Geometry', 'Find perimeters of non-rectangular quadrilaterals and figures made from triangles and quadrilaterals.', 'geometry', 'fa-draw-polygon', ['kind' => 'composite-perimeter']),
                self::topic('g4-whole-numbers-million', 'Whole Numbers up to 1,000,000', 'First Term', 'Number and Algebra', 'Read, write, use place value, compare, round, and estimate with 6-digit numbers.', 'number-sense', 'fa-arrow-down-1-9', ['kind' => 'whole-numbers', 'limit' => 1000000]),
                self::topic('g4-multi-add', 'Addition and Subtraction up to 1,000,000', 'First Term', 'Number and Algebra', 'Add and subtract large numbers with or without regrouping.', 'arithmetic', 'fa-plus-minus', ['kind' => 'large-add-subtract']),
                self::topic('g4-multiply', 'Multiplication with Products up to 1,000,000', 'First Term', 'Number and Algebra', 'Multiply multi-digit whole numbers and estimate products.', 'arithmetic', 'fa-xmark', ['kind' => 'multi-digit-multiplication']),
                self::topic('g4-division', 'Division by 1- and 2-Digit Divisors', 'First Term', 'Number and Algebra', 'Divide up to 4-digit numbers and estimate quotients.', 'arithmetic', 'fa-divide', ['kind' => 'multi-digit-division']),

                self::topic('g4-mdas', 'MDAS and Number Sentences', 'Second Term', 'Number and Algebra', 'Represent situations with number sentences and perform two or more operations using MDAS.', 'arithmetic', 'fa-list-ol', ['kind' => 'order-operations']),
                self::topic('g4-unit-conversions', 'Converting Length, Mass, Capacity, and Time', 'Second Term', 'Measurement and Geometry', 'Convert common metric and time units and solve conversion and elapsed-time problems.', 'measurement', 'fa-right-left', ['kind' => 'unit-conversion']),
                self::topic('g4-similar-fractions', 'Adding and Subtracting Similar Fractions', 'Second Term', 'Number and Algebra', 'Work with proper, improper, and mixed numbers, then add and subtract like fractions.', 'fractions', 'fa-chart-pie', ['kind' => 'similar-add-sub']),
                self::topic('g4-equivalent-fractions', 'Dissimilar and Equivalent Fractions', 'Second Term', 'Number and Algebra', 'Represent, compare, order, and generate equivalent fractions.', 'fractions', 'fa-equals', ['kind' => 'compare-equivalent']),
                self::topic('g4-factors-multiples', 'Factors, Multiples, and Simplest Form', 'Second Term', 'Number and Algebra', 'Find factors and multiples up to 100 and reduce fractions to simplest form.', 'number-theory', 'fa-hashtag', ['kind' => 'factors-multiples']),

                self::topic('g4-dissimilar-fractions', 'Adding and Subtracting Dissimilar Fractions', 'Third Term', 'Number and Algebra', 'Add, subtract, and solve multi-step problems with unlike fractions and mixed numbers.', 'fractions', 'fa-plus-minus', ['kind' => 'dissimilar-add-sub']),
                self::topic('g4-line-symmetry', 'Line Symmetry', 'Third Term', 'Measurement and Geometry', 'Identify line symmetry and complete symmetric figures.', 'geometry', 'fa-arrows-left-right-to-line', ['kind' => 'symmetry']),
                self::topic('g4-reflection', 'Reflection and Glide Reflection', 'Third Term', 'Measurement and Geometry', 'Draw images after reflections across a line, including glide reflections.', 'transformations', 'fa-clone', ['kind' => 'reflection']),
                self::topic('g4-line-graphs', 'Tables and Single Line Graphs', 'Third Term', 'Data and Probability', 'Collect time-based data, present and interpret line graphs, and solve data problems.', 'data', 'fa-chart-line', ['kind' => 'line-graph']),
                self::topic('g4-simple-patterns', 'Simple Patterns and Rules', 'Third Term', 'Number and Algebra', 'Describe the rule used to generate a simple pattern.', 'patterns', 'fa-wave-square', ['kind' => 'simple-rule']),
                self::topic('g4-number-sentences', 'Number Sentences and Number Properties', 'Third Term', 'Number and Algebra', 'Complete number sentences representing operation properties and equivalent facts.', 'arithmetic', 'fa-equals', ['kind' => 'number-sentences']),
                self::topic('g4-decimals-fractions', 'Decimals and Their Fraction Relationships', 'Third Term', 'Number and Algebra', 'Represent, read, place, compare, order, round, and convert decimals through hundredths.', 'decimals', 'fa-calculator', ['kind' => 'fraction-relationship', 'places' => 2]),
            ],
            5 => [
                self::topic('g5-time-systems', '12- and 24-Hour Time', 'First Term', 'Measurement and Geometry', 'Describe and convert clock systems and solve related time problems.', 'time', 'fa-clock', ['kind' => 'time-systems']),
                self::topic('g5-world-time', 'World Time Zones', 'First Term', 'Measurement and Geometry', 'Compare world times with Philippine time and solve time-zone problems.', 'time', 'fa-earth-asia', ['kind' => 'time-zones']),
                self::topic('g5-order-operations', 'GMDAS with Whole Numbers', 'First Term', 'Number and Algebra', 'Perform three or more different operations using GMDAS.', 'arithmetic', 'fa-list-ol', ['kind' => 'gmdas']),
                self::topic('g5-fraction-multiply', 'Multiplication of Fractions', 'First Term', 'Number and Algebra', 'Multiply fractions using models and solve multi-step fraction problems.', 'fractions', 'fa-xmark', ['kind' => 'multiply']),
                self::topic('g5-polygon-area', 'Area of Parallelograms, Triangles, and Trapezoids', 'First Term', 'Measurement and Geometry', 'Identify heights, use area formulas, and estimate areas on grids.', 'geometry', 'fa-draw-polygon', ['kind' => 'polygon-area']),
                self::topic('g5-fraction-divide', 'Division of Fractions', 'First Term', 'Number and Algebra', 'Divide fractions using models and solve multi-step fraction problems.', 'fractions', 'fa-divide', ['kind' => 'divide']),
                self::topic('g5-decimal-place-value', 'Decimals through Thousandths', 'First Term', 'Number and Algebra', 'Determine place value and read or write decimal numbers through thousandths.', 'decimals', 'fa-arrow-down-1-9', ['kind' => 'place-value', 'places' => 3]),

                self::topic('g5-decimals', 'Decimal and Fraction Conversion, Comparison, and Rounding', 'Second Term', 'Number and Algebra', 'Convert terminating decimals and fractions, compare or order decimals, and round to thousandths.', 'decimals', 'fa-right-left', ['kind' => 'compare-convert', 'places' => 3]),
                self::topic('g5-decimal-add-subtract', 'Addition and Subtraction of Decimals', 'Second Term', 'Number and Algebra', 'Add, subtract, and solve multi-step decimal and money problems.', 'decimals', 'fa-plus-minus', ['kind' => 'add-subtract', 'places' => 3]),
                self::topic('g5-divisibility', 'Divisibility Rules', 'Second Term', 'Number and Algebra', 'Apply divisibility rules for 2 through 12 to identify factors.', 'number-theory', 'fa-filter-circle-dollar', ['kind' => 'divisibility']),
                self::topic('g5-prime-composite', 'Prime and Composite Numbers', 'Second Term', 'Number and Algebra', 'Distinguish prime from composite numbers using factor reasoning.', 'number-theory', 'fa-hashtag', ['kind' => 'prime-composite']),
                self::topic('g5-double-graphs', 'Double Bar and Double Line Graphs', 'Second Term', 'Data and Probability', 'Choose, construct, interpret, compare, and infer from double graphs.', 'data', 'fa-chart-bar', ['kind' => 'double-graph']),
                self::topic('g5-theoretical-probability', 'Theoretical Probability', 'Second Term', 'Data and Probability', 'List possible outcomes and calculate the theoretical probability of simple events.', 'data', 'fa-dice', ['kind' => 'theoretical-probability']),

                self::topic('g5-decimal-multiply', 'Multiplication of Decimals', 'Third Term', 'Number and Algebra', 'Estimate and multiply decimals and solve multi-step problems, including money.', 'decimals', 'fa-xmark', ['kind' => 'multiply', 'places' => 2]),
                self::topic('g5-decimal-divide', 'Division of Decimals', 'Third Term', 'Number and Algebra', 'Estimate and calculate terminating decimal quotients and solve multi-step problems.', 'decimals', 'fa-divide', ['kind' => 'divide', 'places' => 2]),
                self::topic('g5-gmdas-fractions-decimals', 'GMDAS with Fractions and Decimals', 'Third Term', 'Number and Algebra', 'Perform three or more operations involving fractions and decimals using GMDAS.', 'arithmetic', 'fa-list-check', ['kind' => 'gmdas-fractions-decimals']),
                self::topic('g5-prisms-pyramids', 'Prisms, Pyramids, and Nets', 'Third Term', 'Measurement and Geometry', 'Relate plane and solid figures and describe prisms, pyramids, and their nets.', 'geometry', 'fa-cubes', ['kind' => 'solid-figures']),
                self::topic('g5-surface-area', 'Surface Area of Solid Figures', 'Third Term', 'Measurement and Geometry', 'Use nets to find surface area and solve related problems.', 'geometry', 'fa-cube', ['kind' => 'surface-area']),
                self::topic('g5-volume', 'Cubes and Rectangular Prisms', 'Third Term', 'Measurement and Geometry', 'Distinguish cubes and rectangular prisms and estimate volume with non-standard units.', 'geometry', 'fa-box', ['kind' => 'volume-estimate']),
                self::topic('g5-rotation', 'Rotation about a Point', 'Third Term', 'Measurement and Geometry', 'Draw an image after clockwise or counterclockwise rotation about a point.', 'transformations', 'fa-rotate', ['kind' => 'rotation']),
            ],
            6 => [
                self::topic('g6-tessellation', 'Tessellation of Shapes', 'First Term', 'Measurement and Geometry', 'Decide whether shapes tessellate and cover surfaces with triangles, squares, or rectangles.', 'geometry', 'fa-border-none', ['kind' => 'tessellation']),
                self::topic('g6-transformations', 'Translation, Reflection, and Rotation', 'First Term', 'Measurement and Geometry', 'Draw resulting images after translations, reflections, and rotations.', 'transformations', 'fa-arrows-spin', ['kind' => 'combined']),
                self::topic('g6-decimal-operations', 'Four Operations with Decimals', 'First Term', 'Number and Algebra', 'Add, subtract, multiply, and divide decimals and solve multi-step problems, including money.', 'decimals', 'fa-calculator', ['kind' => 'four-operations', 'places' => 4]),
                self::topic('g6-fraction-operations', 'Operations with Fractions, Whole Numbers, and Mixed Numbers', 'First Term', 'Number and Algebra', 'Multiply and divide combinations of fractions, whole numbers, and mixed numbers.', 'fractions', 'fa-chart-pie', ['kind' => 'mixed-operations']),
                self::topic('g6-ratios', 'Ratios', 'First Term', 'Number and Algebra', 'Describe part-whole and part-part relationships, write equivalent ratios, and solve problems.', 'ratios', 'fa-code-compare', ['kind' => 'ratios']),

                self::topic('g6-ratio-proportion', 'Ratio and Proportion', 'Second Term', 'Number and Algebra', 'Model ratios and proportions with tables or double number lines and solve related problems.', 'ratios', 'fa-scale-balanced', ['kind' => 'proportions']),
                self::topic('g6-percentages', 'Percentages, Fractions, and Decimals', 'Second Term', 'Number and Algebra', 'Explain relationships among percentages, fractions, and decimals and apply percentages.', 'ratios', 'fa-percent', ['kind' => 'percentages']),
                self::topic('g6-exponents-gemdas', 'Exponential Form and GEMDAS', 'Second Term', 'Number and Algebra', 'Write and evaluate exponential form and use GEMDAS in calculations with exponents.', 'exponents', 'fa-superscript', ['kind' => 'gemdas']),
                self::topic('g6-volume-capacity-units', 'Units of Volume and Capacity', 'Second Term', 'Measurement and Geometry', 'Choose appropriate units and convert between cubic centimeters and liters.', 'measurement', 'fa-right-left', ['kind' => 'volume-capacity']),
                self::topic('g6-prism-volume', 'Volume of Cubes and Rectangular Prisms', 'Second Term', 'Measurement and Geometry', 'Calculate volumes using standard units and solve volume and capacity problems.', 'geometry', 'fa-cube', ['kind' => 'volume']),
                self::topic('g6-composite-measurement', 'Perimeter and Area of Composite Figures', 'Second Term', 'Measurement and Geometry', 'Convert square units and find perimeter or area of triangles, quadrilaterals, and composite figures.', 'geometry', 'fa-draw-polygon', ['kind' => 'composite-area-perimeter']),

                self::topic('g6-circle-parts', 'Parts and Circumference of a Circle', 'Third Term', 'Measurement and Geometry', 'Draw and describe circles, approximate pi, and calculate circumference.', 'circles', 'fa-circle-notch', ['kind' => 'parts-circumference']),
                self::topic('g6-circle-area', 'Area of a Circle', 'Third Term', 'Measurement and Geometry', 'Explore and use the formula for the area of a circle.', 'circles', 'fa-circle', ['kind' => 'area']),
                self::topic('g6-composite-circle-area', 'Area of Composite Figures with Circles', 'Third Term', 'Measurement and Geometry', 'Find areas of figures made from polygons, circles, and semicircles.', 'circles', 'fa-circle-half-stroke', ['kind' => 'composite-area']),
                self::topic('g6-pie-graphs', 'Pie Graphs', 'Third Term', 'Data and Probability', 'Calculate sectors, construct and interpret pie graphs, and draw conclusions from data.', 'data', 'fa-chart-pie', ['kind' => 'pie-graph']),
                self::topic('g6-gcf-lcm', 'GCF and LCM', 'Third Term', 'Number and Algebra', 'Find common factors, greatest common factors, common multiples, and least common multiples.', 'number-theory', 'fa-hashtag', ['kind' => 'gcf-lcm']),
            ],
            default => throw new InvalidArgumentException('Practice is available for Grades 1 through 6.'),
        };
    }

    public static function find(int $grade, string $key): ?array
    {
        foreach (self::forGrade($grade) as $topic) {
            if ($topic['key'] === $key) {
                return $topic;
            }
        }

        return self::legacy($grade)[$key] ?? null;
    }

    public static function isVisible(int $grade, string $key): bool
    {
        foreach (self::forGrade($grade) as $topic) {
            if ($topic['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Preserve questions that may already be open from the first four-topic
     * release without showing out-of-scope topics in the new curriculum map.
     */
    private static function legacy(int $grade): array
    {
        $legacy = [
            1 => [
                'g1-subtract-20' => self::topic('g1-subtract-20', 'Subtraction within 20', 'Legacy', 'Number and Algebra', 'Previously generated subtraction practice.', 'subtraction', 'fa-minus', ['limit' => 20]),
                'g1-compare-100' => self::topic('g1-compare-100', 'Comparing Numbers up to 100', 'Legacy', 'Number and Algebra', 'Previously generated comparison practice.', 'comparison', 'fa-scale-balanced', ['limit' => 100]),
            ],
            2 => [
                'g2-add-100' => self::topic('g2-add-100', 'Addition with Sums up to 100', 'Legacy', 'Number and Algebra', 'Previously generated addition practice.', 'addition', 'fa-plus', ['limit' => 100]),
                'g2-subtract-100' => self::topic('g2-subtract-100', 'Subtraction within 100', 'Legacy', 'Number and Algebra', 'Previously generated subtraction practice.', 'subtraction', 'fa-minus', ['limit' => 100]),
                'g2-place-value' => self::topic('g2-place-value', 'Place Value in 3-Digit Numbers', 'Legacy', 'Number and Algebra', 'Previously generated place-value practice.', 'place-value', 'fa-cubes-stacked', ['digits' => 3]),
            ],
            3 => [
                'g3-unit-fractions' => self::topic('g3-unit-fractions', 'Unit Fractions', 'Legacy', 'Number and Algebra', 'Previously generated fraction practice.', 'fractions', 'fa-chart-pie', ['kind' => 'unit-similar']),
                'g3-perimeter' => self::topic('g3-perimeter', 'Perimeter', 'Legacy', 'Measurement and Geometry', 'Previously generated perimeter practice.', 'geometry', 'fa-draw-polygon', ['kind' => 'perimeter']),
            ],
            5 => [
                'g5-fraction-add' => self::topic('g5-fraction-add', 'Adding Similar Fractions', 'Legacy', 'Number and Algebra', 'Previously generated fraction practice.', 'fractions', 'fa-plus', ['kind' => 'similar-add-sub']),
            ],
            6 => [
                'g6-integers' => self::topic('g6-integers', 'Integers', 'Legacy', 'Number and Algebra', 'Previously generated integer practice.', 'legacy-integers', 'fa-temperature-half'),
                'g6-expressions' => self::topic('g6-expressions', 'Expressions', 'Legacy', 'Number and Algebra', 'Previously generated expression practice.', 'legacy-expressions', 'fa-superscript'),
            ],
        ];

        return $legacy[$grade] ?? [];
    }

    private static function topic(
        string $key,
        string $title,
        string $term,
        string $strand,
        string $summary,
        string $template,
        string $icon,
        array $options = []
    ): array {
        $style = match ($strand) {
            'Measurement and Geometry' => ['world' => 'Geometry Galaxy', 'color' => '#f472b6'],
            'Data and Probability' => ['world' => 'Data Dimension', 'color' => '#a78bfa'],
            default => ['world' => 'Number Nexus', 'color' => '#22d3ee'],
        };

        return [
            'key' => $key,
            'title' => $title,
            'term' => $term,
            'strand' => $strand,
            'summary' => $summary,
            'template' => $template,
            'options' => $options,
            'icon' => $icon,
        ] + $style;
    }
}
