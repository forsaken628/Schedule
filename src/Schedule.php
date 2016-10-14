<?php

namespace Schedule;

use Carbon\Carbon;

/**
 *
 * @property string $start_at
 * @property integer $duration
 * @property integer $has_repeat
 * @property integer $weekly
 * 0x40=SUNDAY 0x20=MONDAY 0x10=TUESDAY ... 0x01=SATURDAY
 *
 * @property integer $biweekly
 * 0x40=SUNDAY 0x20=MONDAY 0x10=TUESDAY ... 0x01=SATURDAY
 *
 * @property integer $has_end
 * @property integer $end_on
 * @property string $end_at
 *
 * @property array $periodPlans
 * @property string $description
 *
 */
class Schedule
{
    const NO_REPEAT = 0;
    const DAILY_REPEAT = 1;
    const WEEKLY_REPEAT = 2;
    const BIWEEKLY_REPEAT = 3;
    const MONTH_REPEAT = 4;
    const NEVER_END = 0;
    const END_ON_COUNT = 1;
    const END_AT_TIME = 2;

    private $start_at, $duration,
        $has_repeat, $weekly, $biweekly,
        $has_end, $end_on, $end_at;

    public function __construct($config = [])
    {
        $this->start_at = isset($config['start_at']) ? $config['start_at'] : time();
        $this->duration = isset($config['duration']) ? $config['duration'] : 3600;
        if (isset($config['daily']) && $config['daily']) {
            $this->has_repeat = Schedule::DAILY_REPEAT;
        } elseif (isset($config['weekly'])) {
            $this->has_repeat = Schedule::WEEKLY_REPEAT;
            $this->weekly = $config['weekly'];
        } elseif (isset($config['biweekly'])) {
            $this->has_repeat = Schedule::BIWEEKLY_REPEAT;
            $this->biweekly = $config['biweekly'];
        } elseif (isset($config['month']) && $config['month']) {
            $this->has_repeat = Schedule::MONTH_REPEAT;
        } else {
            $this->has_repeat = Schedule::NO_REPEAT;
        }
        if (isset($config['end_on'])) {
            $this->has_end = Schedule::END_ON_COUNT;
            $this->end_on = $config['end_on'];
        } elseif (isset($config['end_at'])) {
            $this->has_end = Schedule::END_AT_TIME;
            $this->end_on = $config['end_at'];
        } else {
            $this->has_end = Schedule::NEVER_END;
        }
    }

    public function getDescription()
    {
        $d = date('Y-m-d起,', $this->start_at);
        switch ($this->has_repeat) {
            case Schedule::NO_REPEAT:
                $d = date('Y-m-d H:i:s', $this->start_at);
                break;
            case Schedule::DAILY_REPEAT:
                $d .= '每天' . date('H:i:s', $this->start_at);
                break;
            case Schedule::WEEKLY_REPEAT:
                $a = [];
                $b = ['日', '一', '二', '三', '四', '五', '六'];
                for ($i = 1; $i < 7; $i++) {
                    if (0x40 >> $i & $this->weekly) {
                        $a[] = $b[$i];
                    }
                }
                if (0x40 & $this->weekly) {
                    $a[] = $b[0];
                }
                $d .= '每周星期' . implode('，', $a) . date('H:i:s', $this->start_at);
                break;
            case Schedule::BIWEEKLY_REPEAT:
                $a = [];
                $b = ['日', '一', '二', '三', '四', '五', '六'];
                for ($i = 1; $i < 7; $i++) {
                    if (0x40 >> $i & $this->biweekly) {
                        $a[] = $b[$i];
                    }
                }
                if (0x40 & $this->biweekly) {
                    $a[] = $b[0];
                }
                $d .= '每两周的星期' . implode('，', $a) . date('H:i:s', $this->start_at);
                break;
            case Schedule::MONTH_REPEAT:
                $d .= '每月' . date('d日 H:i:s', $this->start_at);
                break;
        }
        if ($this->has_repeat && $this->has_end) {
            switch ($this->has_end) {
                case Schedule::END_ON_COUNT:
                    $d .= "(共{$this->end_on}次)";
                    break;
                case Schedule::END_AT_TIME:
                    $d .= '(至' . date('Y-m-d H:i:s', $this->end_at) . '止)';
                    break;
            }
        }
        return $d;
    }

    /**
     * @param integer $upperLim
     * @param integer $lowerLim
     * @return array
     * @throws \Exception
     */
    public function getPlans($upperLim, $lowerLim)
    {
        //所有时间戳范围均为左闭右开区间。
        $upperLim = Carbon::createFromTimestamp($upperLim);
        $lowerLim = Carbon::createFromTimestamp($lowerLim);
        $startAt = Carbon::createFromTimestamp($this->start_at);
        $endAt = $startAt->copy()->addSeconds($this->duration);
        $lastEnd = Carbon::createFromTimestamp($this->end_at);
        $re = [];
        switch ($this->has_repeat) {
            case Schedule::NO_REPEAT:
                if ($startAt < $lowerLim && $endAt > $upperLim) {
                    $re[] = [$startAt, $endAt];
                }
                return $re;
                break;
            case Schedule::DAILY_REPEAT:
                if ($endAt <= $upperLim) {
                    $m = $startAt->copy()->addDays($upperLim->diffInDays($endAt));//名义最近结束
                    $n = $m->copy()->subSeconds($this->duration);
                } elseif ($startAt < $lowerLim && $endAt > $upperLim) {
                    $n = $startAt;
                    $m = $n->copy()->addSeconds($this->duration);
                } else {
                    return $re;
                }
                if ($this->has_end == Schedule::END_ON_COUNT) {
                    if (!$this->end_on) {
                        throw new \Exception("Null Value");
                    }
                    $lastEnd = $endAt->copy()->addDays($this->end_on - 1);
                }
                while ($n <= $lowerLim) {
                    if ($this->has_end != Schedule::NEVER_END && $m > $lastEnd) {
                        break;
                    }
                    if ($n > $upperLim) {
                        $re[] = [$n->copy(), $m->copy()];
                    }
                    $n->addDay();
                    $m->addDay();
                }
                return $re;
                break;
            case Schedule::WEEKLY_REPEAT:
                if (!$this->weekly) {
                    throw new \Exception("Error Value");
                }
                if ($endAt <= $upperLim) {
                    $m = $endAt->copy()->addWeeks($upperLim->diffInWeeks($endAt));//名义最近结束
                    $n = $m->copy()->subSeconds($this->duration);
                } elseif ($startAt < $lowerLim && $endAt > $upperLim) {
                    $n = $startAt;
                    $m = $n->copy()->addSeconds($this->duration);
                } else {
                    return $re;
                }
                if ($this->has_end == Schedule::END_ON_COUNT) {
                    if (!$this->end_on) {
                        throw new \Exception("Null Value");
                    }
                    $c = 0;
                    for ($i = 0; $i < 7; $i++) {
                        if ($this->weekly & (0x40 >> $i)) {
                            $c++;//每周次数
                        }
                    }
                    if ($endAt <= $upperLim) {
                        $count = $this->end_on - $upperLim->diffInWeeks($endAt) * $c;
                    } else {
                        $count = $this->end_on;
                    }
                }
                while ($n <= $lowerLim) {
                    if ($this->has_end == Schedule::END_AT_TIME && $m > $lastEnd) {
                        break;
                    }
                    if (0x40 >> $n->dayOfWeek & $this->weekly) {
                        if ($this->has_end == Schedule::END_ON_COUNT) {
                            if ($count <= 0) {
                                break;
                            } else {
                                $count--;
                            }
                        }
                        if ($n >= $upperLim) {
                            $re[] = [$n->copy(), $m->copy()];
                        }
                    }
                    $n->addDay();
                    $m->addDay();
                }
                return $re;
                break;
            case Schedule::BIWEEKLY_REPEAT:
                if (!$this->biweekly) {
                    throw new \Exception("Error Value");
                }
                if ($endAt <= $upperLim) {
                    $m = $endAt->copy()->addWeeks(intval($upperLim->diffInWeeks($endAt) / 2) * 2);//名义最近结束
                    $n = $m->copy()->subSeconds($this->duration);
                } elseif ($startAt < $lowerLim && $endAt > $upperLim) {
                    $n = $startAt->copy();
                    $m = $n->copy()->addSeconds($this->duration);
                } else {
                    return $re;
                }
                if ($this->has_end == Schedule::END_ON_COUNT) {
                    if (!$this->end_on) {
                        throw new \Exception("Null Value");
                    }
                    $c = 0;
                    for ($i = 0; $i < 7; $i++) {
                        if ($this->biweekly & (0x40 >> $i)) {
                            $c++;//每周次数
                        }
                    }
                    if ($endAt <= $upperLim) {
                        $count = $this->end_on - intval($upperLim->diffInWeeks($endAt) / 2) * $c;
                    } else {
                        $count = $this->end_on;
                    }
                }
                while ($n <= $lowerLim) {
                    if ($this->has_end == Schedule::END_AT_TIME && $m > $lastEnd) {
                        break;
                    }
                    if (($n->weekOfYear % 2 == $startAt->weekOfYear % 2)
                        && 0x40 >> $n->dayOfWeek & $this->biweekly
                    ) {
                        if ($this->has_end == Schedule::END_ON_COUNT) {
                            if ($count <= 0) {
                                break;
                            } else {
                                $count--;
                            }
                        }
                        if ($n >= $upperLim) {
                            $re[] = [$n->copy(), $m->copy()];
                        }
                    }
                    $n->addDay();
                    $m->addDay();
                }
                return $re;
                break;
            case Schedule::MONTH_REPEAT:
                if ($startAt >= $lowerLim) {
                    return $re;
                }
                if ($this->has_end == Schedule::END_ON_COUNT) {
                    $count = $this->end_on;
                }
                for ($i = 0; ; $i++) {
                    $n = $startAt->copy()->addMonths($i);
                    if ($n->day != $startAt->day) {
                        $n->subMonth()->day = $n->daysInMonth;
                    }
                    $m = $n->copy()->addSeconds($this->duration);
                    if ($n > $lowerLim) {
                        break;
                    }
                    if ($this->has_end == Schedule::END_AT_TIME && $m > $lastEnd) {
                        break;
                    }
                    if ($this->has_end == Schedule::END_ON_COUNT) {
                        if ($count <= 0) {
                            break;
                        } else {
                            $count--;
                        }
                    }
                    if ($n >= $upperLim) {
                        $re[] = [$n->copy(), $m->copy()];
                    }
                }
                break;
            default:
                throw new \Exception("Error Value");
        }
        return $re;
    }
}
