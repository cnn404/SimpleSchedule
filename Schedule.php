<?php

namespace Lib;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * CRON expression parser that can determine whether or not a CRON expression is
 * due to run, the next run date and previous run date of a CRON expression.
 * The determinations made by this class are accurate if checked run once per
 * minute (seconds are dropped from date time comparisons).
 *
 * Schedule parts must map to:
 * minute [0-59], hour [0-23], day of month, month [1-12|JAN-DEC], day of week
 * [1-7|MON-SUN], and an optional year.
 *
 * @see http://en.wikipedia.org/wiki/Cron
 */
interface FieldFactoryInterface
{
    public function getField(int $position): FieldInterface;
}

interface FieldInterface
{
    /**
     * Check if the respective value of a DateTime field satisfies a CRON exp.
     *
     * @param DateTimeInterface $date DateTime object to check
     * @param string $value CRON expression to test against
     *
     * @return bool Returns TRUE if satisfied, FALSE otherwise
     * @internal
     */
    public function isSatisfiedBy(DateTimeInterface $date, $value, bool $invert): bool;

    /**
     * When a CRON expression is not satisfied, this method is used to increment
     * or decrement a DateTime object by the unit of the cron field.
     *
     * @param DateTimeInterface $date DateTime object to change
     * @param bool $invert (optional) Set to TRUE to decrement
     * @param string|null $parts (optional) Set parts to use
     *
     * @return FieldInterface
     * @internal
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): FieldInterface;

    /**
     * Validates a CRON expression for a given field.
     *
     * @param string $value CRON expression value to validate
     *
     * @return bool Returns TRUE if valid, FALSE otherwise
     */
    public function validate(string $value): bool;
}

abstract class AbstractField implements FieldInterface
{
    /**
     * Full range of values that are allowed for this field type.
     *
     * @var array
     */
    protected $fullRange = [];

    /**
     * Literal values we need to convert to integers.
     *
     * @var array
     */
    protected $literals = [];

    /**
     * Start value of the full range.
     *
     * @var int
     */
    protected $rangeStart;

    /**
     * End value of the full range.
     *
     * @var int
     */
    protected $rangeEnd;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->fullRange = range($this->rangeStart, $this->rangeEnd);
    }

    /**
     * Check to see if a field is satisfied by a value.
     *
     * @param int $dateValue Date value to check
     * @param string $value Value to test
     *
     * @return bool
     * @internal
     */
    public function isSatisfied(int $dateValue, string $value): bool
    {
        if ($this->isIncrementsOfRanges($value)) {
            return $this->isInIncrementsOfRanges($dateValue, $value);
        }

        if ($this->isRange($value)) {
            return $this->isInRange($dateValue, $value);
        }

        return '*' === $value || $dateValue === (int)$value;
    }

    /**
     * Check if a value is a range.
     *
     * @param string $value Value to test
     *
     * @return bool
     * @internal
     */
    public function isRange(string $value): bool
    {
        return false !== strpos($value, '-');
    }

    /**
     * Check if a value is an increments of ranges.
     *
     * @param string $value Value to test
     *
     * @return bool
     * @internal
     */
    public function isIncrementsOfRanges(string $value): bool
    {
        return false !== strpos($value, '/');
    }

    /**
     * Test if a value is within a range.
     *
     * @param int $dateValue Set date value
     * @param string $value Value to test
     *
     * @return bool
     * @internal
     */
    public function isInRange(int $dateValue, $value): bool
    {
        $parts = array_map(
            function ($value) {
                $value = trim($value);

                return $this->convertLiterals($value);
            },
            explode('-', $value, 2)
        );

        return $dateValue >= $parts[0] && $dateValue <= $parts[1];
    }

    /**
     * Test if a value is within an increments of ranges (offset[-to]/step size).
     *
     * @param int $dateValue Set date value
     * @param string $value Value to test
     *
     * @return bool
     * @internal
     */
    public function isInIncrementsOfRanges(int $dateValue, string $value): bool
    {
        $chunks = array_map('trim', explode('/', $value, 2));
        $range = $chunks[0];
        $step = $chunks[1] ?? 0;

        // No step or 0 steps aren't cool
        /** @phpstan-ignore-next-line */
        if (null === $step || '0' === $step || 0 === $step) {
            return false;
        }

        // Expand the * to a full range
        if ('*' === $range) {
            $range = $this->rangeStart . '-' . $this->rangeEnd;
        }

        // Generate the requested small range
        $rangeChunks = explode('-', $range, 2);
        $rangeStart = (int)$rangeChunks[0];
        $rangeEnd = $rangeChunks[1] ?? $rangeStart;
        $rangeEnd = (int)$rangeEnd;

        if ($rangeStart < $this->rangeStart || $rangeStart > $this->rangeEnd || $rangeStart > $rangeEnd) {
            throw new \OutOfRangeException('Invalid range start requested');
        }

        if ($rangeEnd < $this->rangeStart || $rangeEnd > $this->rangeEnd || $rangeEnd < $rangeStart) {
            throw new \OutOfRangeException('Invalid range end requested');
        }

        // Steps larger than the range need to wrap around and be handled
        // slightly differently than smaller steps

        // UPDATE - This is actually false. The C implementation will allow a
        // larger step as valid syntax, it never wraps around. It will stop
        // once it hits the end. Unfortunately this means in future versions
        // we will not wrap around. However, because the logic exists today
        // per the above documentation, fixing the bug from #89
        if ($step > $this->rangeEnd) {
            $thisRange = [$this->fullRange[$step % \count($this->fullRange)]];
        } else {
            if ($step > ($rangeEnd - $rangeStart)) {
                $thisRange[$rangeStart] = (int)$rangeStart;
            } else {
                $thisRange = range($rangeStart, $rangeEnd, (int)$step);
            }
        }

        return \in_array($dateValue, $thisRange, true);
    }

    /**
     * Returns a range of values for the given cron expression.
     *
     * @param string $expression The expression to evaluate
     * @param int $max Maximum offset for range
     *
     * @return array
     */
    public function getRangeForExpression(string $expression, int $max): array
    {
        $values = [];
        $expression = $this->convertLiterals($expression);

        if (false !== strpos($expression, ',')) {
            $ranges = explode(',', $expression);
            $values = [];
            foreach ($ranges as $range) {
                $expanded = $this->getRangeForExpression($range, $this->rangeEnd);
                $values = array_merge($values, $expanded);
            }

            return $values;
        }

        if ($this->isRange($expression) || $this->isIncrementsOfRanges($expression)) {
            if (!$this->isIncrementsOfRanges($expression)) {
                [$offset, $to] = explode('-', $expression);
                $offset = $this->convertLiterals($offset);
                $to = $this->convertLiterals($to);
                $stepSize = 1;
            } else {
                $range = array_map('trim', explode('/', $expression, 2));
                $stepSize = $range[1] ?? 0;
                $range = $range[0];
                $range = explode('-', $range, 2);
                $offset = $range[0];
                $to = $range[1] ?? $max;
            }
            $offset = '*' === $offset ? $this->rangeStart : $offset;
            if ($stepSize >= $this->rangeEnd) {
                $values = [$this->fullRange[$stepSize % \count($this->fullRange)]];
            } else {
                for ($i = $offset; $i <= $to; $i += $stepSize) {
                    $values[] = (int)$i;
                }
            }
            sort($values);
        } else {
            $values = [$expression];
        }

        return $values;
    }

    /**
     * Convert literal.
     *
     * @param string $value
     *
     * @return string
     */
    protected function convertLiterals(string $value): string
    {
        if (\count($this->literals)) {
            $key = array_search(strtoupper($value), $this->literals, true);
            if (false !== $key) {
                return (string)$key;
            }
        }

        return $value;
    }

    /**
     * Checks to see if a value is valid for the field.
     *
     * @param string $value
     *
     * @return bool
     */
    public function validate(string $value): bool
    {
        $value = $this->convertLiterals($value);

        // All fields allow * as a valid value
        if ('*' === $value) {
            return true;
        }

        // Validate each chunk of a list individually
        if (false !== strpos($value, ',')) {
            foreach (explode(',', $value) as $listItem) {
                if (!$this->validate($listItem)) {
                    return false;
                }
            }

            return true;
        }

        if (false !== strpos($value, '/')) {
            [$range, $step] = explode('/', $value);

            // Don't allow numeric ranges
            if (is_numeric($range)) {
                return false;
            }

            return $this->validate($range) && filter_var($step, FILTER_VALIDATE_INT);
        }

        if (false !== strpos($value, '-')) {
            if (substr_count($value, '-') > 1) {
                return false;
            }

            $chunks = explode('-', $value);
            $chunks[0] = $this->convertLiterals($chunks[0]);
            $chunks[1] = $this->convertLiterals($chunks[1]);

            if ('*' === $chunks[0] || '*' === $chunks[1]) {
                return false;
            }

            return $this->validate($chunks[0]) && $this->validate($chunks[1]);
        }

        if (!is_numeric($value)) {
            return false;
        }

        if (false !== strpos($value, '.')) {
            return false;
        }

        // We should have a numeric by now, so coerce this into an integer
        $value = (int)$value;

        return \in_array($value, $this->fullRange, true);
    }

    protected function timezoneSafeModify(DateTimeInterface $dt, string $modification): DateTimeInterface
    {
        $timezone = $dt->getTimezone();
        $dt = $dt->setTimezone(new \DateTimeZone("UTC"));
        $dt = $dt->modify($modification);
        $dt = $dt->setTimezone($timezone);
        return $dt;
    }

    protected function setTimeHour(DateTimeInterface $date, bool $invert, int $originalTimestamp): DateTimeInterface
    {
        $date = $date->setTime((int)$date->format('H'), ($invert ? 59 : 0));

        // setTime caused the offset to change, moving time in the wrong direction
        $actualTimestamp = $date->format('U');
        if ((!$invert) && ($actualTimestamp <= $originalTimestamp)) {
            $date = $this->timezoneSafeModify($date, "+1 hour");
        } elseif ($invert && ($actualTimestamp >= $originalTimestamp)) {
            $date = $this->timezoneSafeModify($date, "-1 hour");
        }

        return $date;
    }
}

class DayOfMonthField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected $rangeStart = 1;

    /**
     * {@inheritdoc}
     */
    protected $rangeEnd = 31;

    /**
     * Get the nearest day of the week for a given day in a month.
     *
     * @param int $currentYear Current year
     * @param int $currentMonth Current month
     * @param int $targetDay Target day of the month
     *
     * @return \DateTime|null Returns the nearest date
     */
    private static function getNearestWeekday(int $currentYear, int $currentMonth, int $targetDay): ?DateTime
    {
        $tday = str_pad((string)$targetDay, 2, '0', STR_PAD_LEFT);
        $target = DateTime::createFromFormat('Y-m-d', "{$currentYear}-{$currentMonth}-{$tday}");

        if ($target === false) {
            return null;
        }

        $currentWeekday = (int)$target->format('N');

        if ($currentWeekday < 6) {
            return $target;
        }

        $lastDayOfMonth = $target->format('t');
        foreach ([-1, 1, -2, 2] as $i) {
            $adjusted = $targetDay + $i;
            if ($adjusted > 0 && $adjusted <= $lastDayOfMonth) {
                $target->setDate($currentYear, $currentMonth, $adjusted);

                if ((int)$target->format('N') < 6 && (int)$target->format('m') === $currentMonth) {
                    return $target;
                }
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(DateTimeInterface $date, $value, bool $invert): bool
    {
        // ? states that the field value is to be skipped
        if ('?' === $value) {
            return true;
        }

        $fieldValue = $date->format('d');

        // Check to see if this is the last day of the month
        if ('L' === $value) {
            return $fieldValue === $date->format('t');
        }

        // Check to see if this is the nearest weekday to a particular value
        if ($wPosition = strpos($value, 'W')) {
            // Parse the target day
            $targetDay = (int)substr($value, 0, $wPosition);
            // Find out if the current day is the nearest day of the week
            $nearest = self::getNearestWeekday(
                (int)$date->format('Y'),
                (int)$date->format('m'),
                $targetDay
            );
            if ($nearest) {
                return $date->format('j') === $nearest->format('j');
            }

            throw new \RuntimeException('Unable to return nearest weekday');
        }

        return $this->isSatisfied((int)$date->format('d'), $value);
    }

    /**
     * @inheritDoc
     *
     * @param \DateTime|\DateTimeImmutable $date
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): FieldInterface
    {
        if (!$invert) {
            $date = $date->add(new \DateInterval('P1D'));
            $date = $date->setTime(0, 0);
        } else {
            $date = $date->sub(new \DateInterval('P1D'));
            $date = $date->setTime(23, 59);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(string $value): bool
    {
        $basicChecks = parent::validate($value);

        // Validate that a list don't have W or L
        if (false !== strpos($value, ',') && (false !== strpos($value, 'W') || false !== strpos($value, 'L'))) {
            return false;
        }

        if (!$basicChecks) {
            if ('?' === $value) {
                return true;
            }

            if ('L' === $value) {
                return true;
            }

            if (preg_match('/^(.*)W$/', $value, $matches)) {
                return $this->validate($matches[1]);
            }

            return false;
        }

        return $basicChecks;
    }
}

class DayOfWeekField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected $rangeStart = 0;

    /**
     * {@inheritdoc}
     */
    protected $rangeEnd = 7;

    /**
     * @var array Weekday range
     */
    protected $nthRange;

    /**
     * {@inheritdoc}
     */
    protected $literals = [1 => 'MON', 2 => 'TUE', 3 => 'WED', 4 => 'THU', 5 => 'FRI', 6 => 'SAT', 7 => 'SUN'];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->nthRange = range(1, 5);
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public function isSatisfiedBy(DateTimeInterface $date, $value, bool $invert): bool
    {
        if ('?' === $value) {
            return true;
        }

        // Convert text day of the week values to integers
        $value = $this->convertLiterals($value);

        $currentYear = (int)$date->format('Y');
        $currentMonth = (int)$date->format('m');
        $lastDayOfMonth = (int)$date->format('t');

        // Find out if this is the last specific weekday of the month
        if ($lPosition = strpos($value, 'L')) {
            $weekday = $this->convertLiterals(substr($value, 0, $lPosition));
            $weekday %= 7;

            $daysInMonth = (int)$date->format('t');
            $remainingDaysInMonth = $daysInMonth - (int)$date->format('d');
            return (($weekday === (int)$date->format('w')) && ($remainingDaysInMonth < 7));
        }

        // Handle # hash tokens
        if (strpos($value, '#')) {
            [$weekday, $nth] = explode('#', $value);

            if (!is_numeric($nth)) {
                throw new InvalidArgumentException("Hashed weekdays must be numeric, {$nth} given");
            } else {
                $nth = (int)$nth;
            }

            // 0 and 7 are both Sunday, however 7 matches date('N') format ISO-8601
            if ('0' === $weekday) {
                $weekday = 7;
            }

            $weekday = (int)$this->convertLiterals((string)$weekday);

            // Validate the hash fields
            if ($weekday < 0 || $weekday > 7) {
                throw new InvalidArgumentException("Weekday must be a value between 0 and 7. {$weekday} given");
            }

            if (!\in_array($nth, $this->nthRange, true)) {
                throw new InvalidArgumentException("There are never more than 5 or less than 1 of a given weekday in a month, {$nth} given");
            }

            // The current weekday must match the targeted weekday to proceed
            if ((int)$date->format('N') !== $weekday) {
                return false;
            }

            $tdate = clone $date;
            $tdate = $tdate->setDate($currentYear, $currentMonth, 1);
            $dayCount = 0;
            $currentDay = 1;
            while ($currentDay < $lastDayOfMonth + 1) {
                if ((int)$tdate->format('N') === $weekday) {
                    if (++$dayCount >= $nth) {
                        break;
                    }
                }
                $tdate = $tdate->setDate($currentYear, $currentMonth, ++$currentDay);
            }

            return (int)$date->format('j') === $currentDay;
        }

        // Handle day of the week values
        if (false !== strpos($value, '-')) {
            $parts = explode('-', $value);
            if ('7' === $parts[0]) {
                $parts[0] = 0;
            } elseif ('0' === $parts[1]) {
                $parts[1] = 7;
            }
            $value = implode('-', $parts);
        }

        // Test to see which Sunday to use -- 0 == 7 == Sunday
        $format = \in_array(7, array_map(function ($value) {
            return (int)$value;
        }, str_split($value)), true) ? 'N' : 'w';
        $fieldValue = (int)$date->format($format);

        return $this->isSatisfied($fieldValue, $value);
    }

    /**
     * @inheritDoc
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): FieldInterface
    {
        if (!$invert) {
            $date = $date->add(new \DateInterval('P1D'));
            $date = $date->setTime(0, 0);
        } else {
            $date = $date->sub(new \DateInterval('P1D'));
            $date = $date->setTime(23, 59);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(string $value): bool
    {
        $basicChecks = parent::validate($value);

        if (!$basicChecks) {
            if ('?' === $value) {
                return true;
            }

            // Handle the # value
            if (false !== strpos($value, '#')) {
                $chunks = explode('#', $value);
                $chunks[0] = $this->convertLiterals($chunks[0]);

                if (parent::validate($chunks[0]) && is_numeric($chunks[1]) && \in_array((int)$chunks[1], $this->nthRange, true)) {
                    return true;
                }
            }

            if (preg_match('/^(.*)L$/', $value, $matches)) {
                return $this->validate($matches[1]);
            }

            return false;
        }

        return $basicChecks;
    }
}

class FieldFactory implements FieldFactoryInterface
{
    /**
     * @var array Cache of instantiated fields
     */
    private $fields = [];

    /**
     * Get an instance of a field object for a cron expression position.
     *
     * @param int $position CRON expression position value to retrieve
     *
     * @throws InvalidArgumentException if a position is not valid
     */
    public function getField(int $position): FieldInterface
    {
        return $this->fields[$position] ?? $this->fields[$position] = $this->instantiateField($position);
    }

    private function instantiateField(int $position): FieldInterface
    {
        switch ($position) {
            case CronExpression::MINUTE:
                return new MinutesField();
            case CronExpression::HOUR:
                return new HoursField();
            case CronExpression::DAY:
                return new DayOfMonthField();
            case CronExpression::MONTH:
                return new MonthField();
            case CronExpression::WEEKDAY:
                return new DayOfWeekField();
        }

        throw new InvalidArgumentException(
            ($position + 1) . ' is not a valid position'
        );
    }
}

class HoursField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected $rangeStart = 0;

    /**
     * {@inheritdoc}
     */
    protected $rangeEnd = 23;

    /**
     * @var array|null Transitions returned by DateTimeZone::getTransitions()
     */
    protected $transitions = [];

    /**
     * @var int|null Timestamp of the start of the transitions range
     */
    protected $transitionsStart = null;

    /**
     * @var int|null Timestamp of the end of the transitions range
     */
    protected $transitionsEnd = null;

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(DateTimeInterface $date, $value, bool $invert): bool
    {
        $checkValue = (int)$date->format('H');
        $retval = $this->isSatisfied($checkValue, $value);
        if ($retval) {
            return $retval;
        }

        // Are we on the edge of a transition
        $lastTransition = $this->getPastTransition($date);
        if (($lastTransition !== null) && ($lastTransition["ts"] > ((int)$date->format('U') - 3600))) {
            $dtLastOffset = clone $date;
            $this->timezoneSafeModify($dtLastOffset, "-1 hour");
            $lastOffset = $dtLastOffset->getOffset();

            $dtNextOffset = clone $date;
            $this->timezoneSafeModify($dtNextOffset, "+1 hour");
            $nextOffset = $dtNextOffset->getOffset();

            $offsetChange = $nextOffset - $lastOffset;
            if ($offsetChange >= 3600) {
                $checkValue -= 1;
                return $this->isSatisfied($checkValue, $value);
            }
            if ((!$invert) && ($offsetChange <= -3600)) {
                $checkValue += 1;
                return $this->isSatisfied($checkValue, $value);
            }
        }

        return $retval;
    }

    public function getPastTransition(DateTimeInterface $date): ?array
    {
        $currentTimestamp = (int)$date->format('U');
        if (
            ($this->transitions === null)
            || ($this->transitionsStart < ($currentTimestamp + 86400))
            || ($this->transitionsEnd > ($currentTimestamp - 86400))
        ) {
            // We start a day before current time so we can differentiate between the first transition entry
            // and a change that happens now
            $dtLimitStart = clone $date;
            $dtLimitStart = $dtLimitStart->modify("-12 months");
            $dtLimitEnd = clone $date;
            $dtLimitEnd = $dtLimitEnd->modify('+12 months');

            $this->transitions = $date->getTimezone()->getTransitions(
                $dtLimitStart->getTimestamp(),
                $dtLimitEnd->getTimestamp()
            );
            if (empty($this->transitions)) {
                return null;
            }
            $this->transitionsStart = $dtLimitStart->getTimestamp();
            $this->transitionsEnd = $dtLimitEnd->getTimestamp();
        }

        $nextTransition = null;
        foreach ($this->transitions as $transition) {
            if ($transition["ts"] > $currentTimestamp) {
                continue;
            }

            if (($nextTransition !== null) && ($transition["ts"] < $nextTransition["ts"])) {
                continue;
            }

            $nextTransition = $transition;
        }

        return ($nextTransition ?? null);
    }

    /**
     * {@inheritdoc}
     *
     * @param string|null $parts
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): FieldInterface
    {
        $originalTimestamp = (int)$date->format('U');

        // Change timezone to UTC temporarily. This will
        // allow us to go back or forwards and hour even
        // if DST will be changed between the hours.
        if (null === $parts || '*' === $parts) {
            if ($invert) {
                $date = $date->sub(new \DateInterval('PT1H'));
            } else {
                $date = $date->add(new \DateInterval('PT1H'));
            }

            $date = $this->setTimeHour($date, $invert, $originalTimestamp);
            return $this;
        }

        $parts = false !== strpos($parts, ',') ? explode(',', $parts) : [$parts];
        $hours = [];
        foreach ($parts as $part) {
            $hours = array_merge($hours, $this->getRangeForExpression($part, 23));
        }

        $current_hour = (int)$date->format('H');
        $position = $invert ? \count($hours) - 1 : 0;
        $countHours = \count($hours);
        if ($countHours > 1) {
            for ($i = 0; $i < $countHours - 1; ++$i) {
                if ((!$invert && $current_hour >= $hours[$i] && $current_hour < $hours[$i + 1]) ||
                    ($invert && $current_hour > $hours[$i] && $current_hour <= $hours[$i + 1])) {
                    $position = $invert ? $i : $i + 1;

                    break;
                }
            }
        }

        $target = (int)$hours[$position];
        $originalHour = (int)$date->format('H');

        $originalDay = (int)$date->format('d');
        $previousOffset = $date->getOffset();

        if (!$invert) {
            if ($originalHour >= $target) {
                $distance = 24 - $originalHour;
                $date = $this->timezoneSafeModify($date, "+{$distance} hours");

                $actualDay = (int)$date->format('d');
                $actualHour = (int)$date->format('H');
                if (($actualDay !== ($originalDay + 1)) && ($actualHour !== 0)) {
                    $offsetChange = ($previousOffset - $date->getOffset());
                    $date = $this->timezoneSafeModify($date, "+{$offsetChange} seconds");
                }

                $originalHour = (int)$date->format('H');
            }

            $distance = $target - $originalHour;
            $date = $this->timezoneSafeModify($date, "+{$distance} hours");
        } else {
            if ($originalHour <= $target) {
                $distance = ($originalHour + 1);
                $date = $this->timezoneSafeModify($date, "-" . $distance . " hours");

                $actualDay = (int)$date->format('d');
                $actualHour = (int)$date->format('H');
                if (($actualDay !== ($originalDay - 1)) && ($actualHour !== 23)) {
                    $offsetChange = ($previousOffset - $date->getOffset());
                    $date = $this->timezoneSafeModify($date, "+{$offsetChange} seconds");
                }

                $originalHour = (int)$date->format('H');
            }

            $distance = $originalHour - $target;
            $date = $this->timezoneSafeModify($date, "-{$distance} hours");
        }

        $date = $this->setTimeHour($date, $invert, $originalTimestamp);

        $actualHour = (int)$date->format('H');
        if ($invert && ($actualHour === ($target - 1) || (($actualHour === 23) && ($target === 0)))) {
            $date = $this->timezoneSafeModify($date, "+1 hour");
        }

        return $this;
    }
}

class MinutesField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected $rangeStart = 0;

    /**
     * {@inheritdoc}
     */
    protected $rangeEnd = 59;

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(DateTimeInterface $date, $value, bool $invert): bool
    {
        if ($value === '?') {
            return true;
        }

        return $this->isSatisfied((int)$date->format('i'), $value);
    }

    /**
     * {@inheritdoc}
     * {@inheritDoc}
     *
     * @param string|null $parts
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): FieldInterface
    {
        if (is_null($parts)) {
            $date = $this->timezoneSafeModify($date, ($invert ? "-" : "+") . "1 minute");
            return $this;
        }

        $current_minute = (int)$date->format('i');

        $parts = false !== strpos($parts, ',') ? explode(',', $parts) : [$parts];
        sort($parts);
        $minutes = [];
        foreach ($parts as $part) {
            $minutes = array_merge($minutes, $this->getRangeForExpression($part, 59));
        }

        $position = $invert ? \count($minutes) - 1 : 0;
        if (\count($minutes) > 1) {
            for ($i = 0; $i < \count($minutes) - 1; ++$i) {
                if ((!$invert && $current_minute >= $minutes[$i] && $current_minute < $minutes[$i + 1]) ||
                    ($invert && $current_minute > $minutes[$i] && $current_minute <= $minutes[$i + 1])) {
                    $position = $invert ? $i : $i + 1;

                    break;
                }
            }
        }

        $target = (int)$minutes[$position];
        $originalMinute = (int)$date->format("i");

        if (!$invert) {
            if ($originalMinute >= $target) {
                $distance = 60 - $originalMinute;
                $date = $this->timezoneSafeModify($date, "+{$distance} minutes");

                $originalMinute = (int)$date->format("i");
            }

            $distance = $target - $originalMinute;
            $date = $this->timezoneSafeModify($date, "+{$distance} minutes");
        } else {
            if ($originalMinute <= $target) {
                $distance = ($originalMinute + 1);
                $date = $this->timezoneSafeModify($date, "-{$distance} minutes");

                $originalMinute = (int)$date->format("i");
            }

            $distance = $originalMinute - $target;
            $date = $this->timezoneSafeModify($date, "-{$distance} minutes");
        }

        return $this;
    }
}

class MonthField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected $rangeStart = 1;

    /**
     * {@inheritdoc}
     */
    protected $rangeEnd = 12;

    /**
     * {@inheritdoc}
     */
    protected $literals = [1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL',
        8 => 'AUG', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DEC',];

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(DateTimeInterface $date, $value, bool $invert): bool
    {
        if ($value === '?') {
            return true;
        }

        $value = $this->convertLiterals($value);

        return $this->isSatisfied((int)$date->format('m'), $value);
    }

    /**
     * @inheritDoc
     *
     * @param \DateTime|\DateTimeImmutable $date
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): FieldInterface
    {
        if (!$invert) {
            $date = $date->modify('first day of next month');
            $date = $date->setTime(0, 0);
        } else {
            $date = $date->modify('last day of previous month');
            $date = $date->setTime(23, 59);
        }

        return $this;
    }
}

class CronExpression
{
    public const MINUTE = 0;
    public const HOUR = 1;
    public const DAY = 2;
    public const MONTH = 3;
    public const WEEKDAY = 4;

    /** @deprecated */
    public const YEAR = 5;

    public const MAPPINGS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /**
     * @var array CRON expression parts
     */
    protected $cronParts;

    /**
     * @var FieldFactoryInterface CRON field factory
     */
    protected $fieldFactory;

    /**
     * @var int Max iteration count when searching for next run date
     */
    protected $maxIterationCount = 1000;

    /**
     * @var array Order in which to test of cron parts
     */
    protected static $order = [
        self::YEAR,
        self::MONTH,
        self::DAY,
        self::WEEKDAY,
        self::HOUR,
        self::MINUTE,
    ];

    /**
     * @var array<string, string>
     */
    private static $registeredAliases = self::MAPPINGS;

    /**
     * Registered a user defined CRON Expression Alias.
     *
     * @throws LogicException If the expression or the alias name are invalid
     *                         or if the alias is already registered.
     */
    public static function registerAlias(string $alias, string $expression): void
    {
        try {
            new self($expression);
        } catch (InvalidArgumentException $exception) {
            throw new LogicException("The expression `$expression` is invalid", 0, $exception);
        }

        $shortcut = strtolower($alias);
        if (1 !== preg_match('/^@\w+$/', $shortcut)) {
            throw new LogicException("The alias `$alias` is invalid. It must start with an `@` character and contain alphanumeric (letters, numbers, regardless of case) plus underscore (_).");
        }

        if (isset(self::$registeredAliases[$shortcut])) {
            throw new LogicException("The alias `$alias` is already registered.");
        }

        self::$registeredAliases[$shortcut] = $expression;
    }

    /**
     * Unregistered a user defined CRON Expression Alias.
     *
     * @throws LogicException If the user tries to unregister a built-in alias
     */
    public static function unregisterAlias(string $alias): bool
    {
        $shortcut = strtolower($alias);
        if (isset(self::MAPPINGS[$shortcut])) {
            throw new LogicException("The alias `$alias` is a built-in alias; it can not be unregistered.");
        }

        if (!isset(self::$registeredAliases[$shortcut])) {
            return false;
        }

        unset(self::$registeredAliases[$shortcut]);

        return true;
    }

    /**
     * Tells whether a CRON Expression alias is registered.
     */
    public static function supportsAlias(string $alias): bool
    {
        return isset(self::$registeredAliases[strtolower($alias)]);
    }

    /**
     * Returns all registered aliases as an associated array where the aliases are the key
     * and their associated expressions are the values.
     *
     * @return array<string, string>
     */
    public static function getAliases(): array
    {
        return self::$registeredAliases;
    }

    /**
     * @deprecated since version 3.0.2, use __construct instead.
     */
    public static function factory(string $expression, FieldFactoryInterface $fieldFactory = null): CronExpression
    {
        /** @phpstan-ignore-next-line */
        return new static($expression, $fieldFactory);
    }

    /**
     * Validate a CronExpression.
     *
     * @param string $expression the CRON expression to validate
     *
     * @return bool True if a valid CRON expression was passed. False if not.
     */
    public static function isValidExpression(string $expression): bool
    {
        try {
            new CronExpression($expression);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    /**
     * Parse a CRON expression.
     *
     * @param string $expression CRON expression (e.g. '8 * * * *')
     * @param null|FieldFactoryInterface $fieldFactory Factory to create cron fields
     * @throws InvalidArgumentException
     */
    public function __construct(string $expression, FieldFactoryInterface $fieldFactory = null)
    {
        $shortcut = strtolower($expression);
        $expression = self::$registeredAliases[$shortcut] ?? $expression;

        $this->fieldFactory = $fieldFactory ?: new FieldFactory();
        $this->setExpression($expression);
    }

    /**
     * Set or change the CRON expression.
     *
     * @param string $value CRON expression (e.g. 8 * * * *)
     *
     * @return CronExpression
     * @throws \InvalidArgumentException if not a valid CRON expression
     *
     */
    public function setExpression(string $value): CronExpression
    {
        $split = preg_split('/\s/', $value, -1, PREG_SPLIT_NO_EMPTY);

        $notEnoughParts = \count($split) < 5;

        $questionMarkInInvalidPart = array_key_exists(0, $split) && $split[0] === '?'
            || array_key_exists(1, $split) && $split[1] === '?'
            || array_key_exists(3, $split) && $split[3] === '?';

        $tooManyQuestionMarks = array_key_exists(2, $split) && $split[2] === '?'
            && array_key_exists(4, $split) && $split[4] === '?';

        if ($notEnoughParts || $questionMarkInInvalidPart || $tooManyQuestionMarks) {
            throw new InvalidArgumentException(
                $value . ' is not a valid CRON expression'
            );
        }

        $this->cronParts = $split;
        foreach ($this->cronParts as $position => $part) {
            $this->setPart($position, $part);
        }

        return $this;
    }

    /**
     * Set part of the CRON expression.
     *
     * @param int $position The position of the CRON expression to set
     * @param string $value The value to set
     *
     * @return CronExpression
     * @throws \InvalidArgumentException if the value is not valid for the part
     *
     */
    public function setPart(int $position, string $value): CronExpression
    {
        if (!$this->fieldFactory->getField($position)->validate($value)) {
            throw new InvalidArgumentException(
                'Invalid CRON field value ' . $value . ' at position ' . $position
            );
        }

        $this->cronParts[$position] = $value;

        return $this;
    }

    /**
     * Set max iteration count for searching next run dates.
     *
     * @param int $maxIterationCount Max iteration count when searching for next run date
     *
     * @return CronExpression
     */
    public function setMaxIterationCount(int $maxIterationCount): CronExpression
    {
        $this->maxIterationCount = $maxIterationCount;

        return $this;
    }

    /**
     * Get a next run date relative to the current date or a specific date
     *
     * @param string|\DateTimeInterface $currentTime Relative calculation date
     * @param int $nth Number of matches to skip before returning a
     *                                                    matching next run date.  0, the default, will return the
     *                                                    current date and time if the next run date falls on the
     *                                                    current date and time.  Setting this value to 1 will
     *                                                    skip the first match and go to the second match.
     *                                                    Setting this value to 2 will skip the first 2
     *                                                    matches and so on.
     * @param bool $allowCurrentDate Set to TRUE to return the current date if
     *                                                    it matches the cron expression.
     * @param null|string $timeZone TimeZone to use instead of the system default
     *
     * @return \DateTime
     * @throws \Exception
     *
     * @throws \RuntimeException on too many iterations
     */
    public function getNextRunDate($currentTime = 'now', int $nth = 0, bool $allowCurrentDate = false, $timeZone = null): DateTime
    {
        return $this->getRunDate($currentTime, $nth, false, $allowCurrentDate, $timeZone);
    }

    /**
     * Get a previous run date relative to the current date or a specific date.
     *
     * @param string|\DateTimeInterface $currentTime Relative calculation date
     * @param int $nth Number of matches to skip before returning
     * @param bool $allowCurrentDate Set to TRUE to return the
     *                                                    current date if it matches the cron expression
     * @param null|string $timeZone TimeZone to use instead of the system default
     *
     * @return \DateTime
     *
     * @throws \Exception
     *
     * @throws \RuntimeException on too many iterations
     * @see \Cron\CronExpression::getNextRunDate
     */
    public function getPreviousRunDate($currentTime = 'now', int $nth = 0, bool $allowCurrentDate = false, $timeZone = null): DateTime
    {
        return $this->getRunDate($currentTime, $nth, true, $allowCurrentDate, $timeZone);
    }

    /**
     * Get multiple run dates starting at the current date or a specific date.
     *
     * @param int $total Set the total number of dates to calculate
     * @param string|\DateTimeInterface|null $currentTime Relative calculation date
     * @param bool $invert Set to TRUE to retrieve previous dates
     * @param bool $allowCurrentDate Set to TRUE to return the
     *                               current date if it matches the cron expression
     * @param null|string $timeZone TimeZone to use instead of the system default
     *
     * @return \DateTime[] Returns an array of run dates
     */
    public function getMultipleRunDates(int $total, $currentTime = 'now', bool $invert = false, bool $allowCurrentDate = false, $timeZone = null): array
    {
        $timeZone = $this->determineTimeZone($currentTime, $timeZone);

        if ('now' === $currentTime) {
            $currentTime = new DateTime();
        } elseif ($currentTime instanceof DateTime) {
            $currentTime = clone $currentTime;
        } elseif ($currentTime instanceof DateTimeImmutable) {
            $currentTime = DateTime::createFromFormat('U', $currentTime->format('U'));
        } elseif (\is_string($currentTime)) {
            $currentTime = new DateTime($currentTime);
        }

//        Assert::isInstanceOf($currentTime, DateTime::class);
        $currentTime->setTimezone(new DateTimeZone($timeZone));

        $matches = [];
        for ($i = 0; $i < $total; ++$i) {
            try {
                $result = $this->getRunDate($currentTime, 0, $invert, $allowCurrentDate, $timeZone);
            } catch (RuntimeException $e) {
                break;
            }

            $allowCurrentDate = false;
            $currentTime = clone $result;
            $matches[] = $result;
        }

        return $matches;
    }

    /**
     * Get all or part of the CRON expression.
     *
     * @param int|string|null $part specify the part to retrieve or NULL to get the full
     *                     cron schedule string
     *
     * @return null|string Returns the CRON expression, a part of the
     *                     CRON expression, or NULL if the part was specified but not found
     */
    public function getExpression($part = null): ?string
    {
        if (null === $part) {
            return implode(' ', $this->cronParts);
        }

        if (array_key_exists($part, $this->cronParts)) {
            return $this->cronParts[$part];
        }

        return null;
    }

    /**
     * Gets the parts of the cron expression as an array.
     *
     * @return string[]
     *   The array of parts that make up this expression.
     */
    public function getParts()
    {
        return $this->cronParts;
    }

    /**
     * Helper method to output the full expression.
     *
     * @return string Full CRON expression
     */
    public function __toString(): string
    {
        return (string)$this->getExpression();
    }

    /**
     * Determine if the cron is due to run based on the current date or a
     * specific date.  This method assumes that the current number of
     * seconds are irrelevant, and should be called once per minute.
     *
     * @param string|\DateTimeInterface $currentTime Relative calculation date
     * @param null|string $timeZone TimeZone to use instead of the system default
     *
     * @return bool Returns TRUE if the cron is due to run or FALSE if not
     */
    public function isDue($currentTime = 'now', $timeZone = null): bool
    {
        $timeZone = $this->determineTimeZone($currentTime, $timeZone);

        if ('now' === $currentTime) {
            $currentTime = new DateTime();
        } elseif ($currentTime instanceof DateTime) {
            $currentTime = clone $currentTime;
        } elseif ($currentTime instanceof DateTimeImmutable) {
            $currentTime = DateTime::createFromFormat('U', $currentTime->format('U'));
        } elseif (\is_string($currentTime)) {
            $currentTime = new DateTime($currentTime);
        }

//        Assert::isInstanceOf($currentTime, DateTime::class);
        $currentTime->setTimezone(new DateTimeZone($timeZone));

        // drop the seconds to 0
        $currentTime->setTime((int)$currentTime->format('H'), (int)$currentTime->format('i'), 0);

        try {
            return $this->getNextRunDate($currentTime, 0, true)->getTimestamp() === $currentTime->getTimestamp();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the next or previous run date of the expression relative to a date.
     *
     * @param string|\DateTimeInterface|null $currentTime Relative calculation date
     * @param int $nth Number of matches to skip before returning
     * @param bool $invert Set to TRUE to go backwards in time
     * @param bool $allowCurrentDate Set to TRUE to return the
     *                               current date if it matches the cron expression
     * @param string|null $timeZone TimeZone to use instead of the system default
     *
     * @return \DateTime
     * @throws Exception
     *
     * @throws \RuntimeException on too many iterations
     */
    protected function getRunDate($currentTime = null, int $nth = 0, bool $invert = false, bool $allowCurrentDate = false, $timeZone = null): DateTime
    {
        $timeZone = $this->determineTimeZone($currentTime, $timeZone);

        if ($currentTime instanceof DateTime) {
            $currentDate = clone $currentTime;
        } elseif ($currentTime instanceof DateTimeImmutable) {
            $currentDate = DateTime::createFromFormat('U', $currentTime->format('U'));
        } elseif (\is_string($currentTime)) {
            $currentDate = new DateTime($currentTime);
        } else {
            $currentDate = new DateTime('now');
        }

//        Assert::isInstanceOf($currentDate, DateTime::class);
        $currentDate->setTimezone(new DateTimeZone($timeZone));
        // Workaround for setTime causing an offset change: https://bugs.php.net/bug.php?id=81074
        $currentDate = DateTime::createFromFormat("!Y-m-d H:iO", $currentDate->format("Y-m-d H:iP"), $currentDate->getTimezone());
        if ($currentDate === false) {
            throw new \RuntimeException('Unable to create date from format');
        }
        $currentDate->setTimezone(new DateTimeZone($timeZone));

        $nextRun = clone $currentDate;

        // We don't have to satisfy * or null fields
        $parts = [];
        $fields = [];
        foreach (self::$order as $position) {
            $part = $this->getExpression($position);
            if (null === $part || '*' === $part) {
                continue;
            }
            $parts[$position] = $part;
            $fields[$position] = $this->fieldFactory->getField($position);
        }

        if (isset($parts[self::DAY]) && isset($parts[self::WEEKDAY])) {
            $domExpression = sprintf('%s %s %s %s *', $this->getExpression(0), $this->getExpression(1), $this->getExpression(2), $this->getExpression(3));
            $dowExpression = sprintf('%s %s * %s %s', $this->getExpression(0), $this->getExpression(1), $this->getExpression(3), $this->getExpression(4));

            $domExpression = new self($domExpression);
            $dowExpression = new self($dowExpression);

            $domRunDates = $domExpression->getMultipleRunDates($nth + 1, $currentTime, $invert, $allowCurrentDate, $timeZone);
            $dowRunDates = $dowExpression->getMultipleRunDates($nth + 1, $currentTime, $invert, $allowCurrentDate, $timeZone);

            if ($parts[self::DAY] === '?' || $parts[self::DAY] === '*') {
                $domRunDates = [];
            }

            if ($parts[self::WEEKDAY] === '?' || $parts[self::WEEKDAY] === '*') {
                $dowRunDates = [];
            }

            $combined = array_merge($domRunDates, $dowRunDates);
            usort($combined, function ($a, $b) {
                return $a->format('Y-m-d H:i:s') <=> $b->format('Y-m-d H:i:s');
            });
            if ($invert) {
                $combined = array_reverse($combined);
            }

            return $combined[$nth];
        }

        // Set a hard limit to bail on an impossible date
        for ($i = 0; $i < $this->maxIterationCount; ++$i) {
            foreach ($parts as $position => $part) {
                $satisfied = false;
                // Get the field object used to validate this part
                $field = $fields[$position];
                // Check if this is singular or a list
                if (false === strpos($part, ',')) {
                    $satisfied = $field->isSatisfiedBy($nextRun, $part, $invert);
                } else {
                    foreach (array_map('trim', explode(',', $part)) as $listPart) {
                        if ($field->isSatisfiedBy($nextRun, $listPart, $invert)) {
                            $satisfied = true;

                            break;
                        }
                    }
                }

                // If the field is not satisfied, then start over
                if (!$satisfied) {
                    $field->increment($nextRun, $invert, $part);

                    continue 2;
                }
            }

            // Skip this match if needed
            if ((!$allowCurrentDate && $nextRun == $currentDate) || --$nth > -1) {
                $this->fieldFactory->getField(self::MINUTE)->increment($nextRun, $invert, $parts[self::MINUTE] ?? null);
                continue;
            }

            return $nextRun;
        }

        // @codeCoverageIgnoreStart
        throw new RuntimeException('Impossible CRON expression');
        // @codeCoverageIgnoreEnd
    }

    /**
     * Workout what timeZone should be used.
     *
     * @param string|\DateTimeInterface|null $currentTime Relative calculation date
     * @param string|null $timeZone TimeZone to use instead of the system default
     *
     * @return string
     */
    protected function determineTimeZone($currentTime, ?string $timeZone): string
    {
        if (null !== $timeZone) {
            return $timeZone;
        }

        if ($currentTime instanceof DateTimeInterface) {
            return $currentTime->getTimezone()->getName();
        }

        return date_default_timezone_get();
    }
}

abstract class Schedule
{
    private array $commands = [];
    private ?string $addCommand = null;
    private $currentTime = '';
    public function __construct()
    {
        $this->currentTime = date('Y-m-d H:i:00');
    }

    public abstract function schedule(Schedule $schedule);

    /**
     * @添加定时任务
     * @param string $cronExpression 定时任务时间表达式，例如： 0 2 * * *
     * @param string $command 脚本路径，例如：Admin.DelAccount.autoDelAccount
     * @return $this
     * @throws Exception
     */
    protected function add(string $cronExpression, string $command): Schedule
    {
        //一次性收集需要执行的任务
        $cron = new CronExpression($cronExpression);
        $previouseTime = date('Y-m-d H:i:s',strtotime('-1 minute',strtotime($this->currentTime)));
        $nextRunDate = $cron->getNextRunDate($previouseTime)->format('Y-m-d H:i:s');
        if ($this->currentTime == $nextRunDate) {
            $this->commands[$command] = [
                'cronExpression' => $cronExpression,
                'logPath' => '/dev/null'
            ];
            $this->addCommand = $command;
        }
        return $this;
    }

    /**
     * @desc 输出日志到指定文件
     * @param $logPath
     * @return void
     */
    protected function outPutLog($logPath)
    {
        if (!empty($this->addCommand)) {
            if (isset($this->commands[$this->addCommand])) {
                $this->commands[$this->addCommand]['logPath'] = $logPath;
                $this->addCommand = null;
            }
        }
    }

    /**
     * @desc 执行当前时间的任务
     * @return void
     */
    public function run(): void
    {
        static::schedule($this);
        foreach ($this->commands as $command => $item) {
            $command = getcwd() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phalapi-cli -s' . ' ' . $command;
            $dir = dirname($item['logPath']);
            !is_dir($dir) && mkdir($dir, 0755, true);
            !file_exists($item['logPath']) && touch($item['logPath']);
            try {
                exec("$command >> " . $item['logPath']);
            }catch (\Exception $exception){
                echo $exception->getTraceAsString() . PHP_EOL;
            }
        }
    }
}

