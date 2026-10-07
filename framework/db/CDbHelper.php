<?php
declare(strict_types=1);

class CDbHelper
{
    /** @var int Минимальная длина sql запроса для сокращения  */
    const MIN_SQL_LENGTH_FOR_SHORTEN = 50;

    /** @var int Минимальное количество значений в IN для сокращения */
    const MIN_VALUES_FOR_SHORTEN = 13;

    /**
     * Обрезает слишком длинные IN (...) конструкции в SQL запросах для удобства чтения логов (и что бы ограничения на размер сообщений в мессенджерах не обрезали весь текст репорта с запросом)
     *
     * @param string $message
     * @return string
     */
    public static function shortenMultipleInClauses(string $message): string
    {
        $result  = $message;
        $matches = [];

        if (preg_match_all('/IN\s*\(([^)]+)\)/i', $message, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $message;
        }

        $matches[0] = array_reverse($matches[0]);
        $matches[1] = array_reverse($matches[1]);

        foreach ($matches[0] as $number => $inString) {
            if (strlen($matches[1][$number][0]) <= self::MIN_SQL_LENGTH_FOR_SHORTEN) {
                continue;
            }
            $idList = array_map('trim', explode(',', $matches[1][$number][0]));
            if (count($idList) < self::MIN_VALUES_FOR_SHORTEN) {
                continue;
            }

            $newIn = 'IN ('
                . implode(',', array_slice($idList, 0, 5))
                . ', ***, '
                . implode(',', array_slice($idList, -5))
                . ')['
                . count($idList)
                . '] ';

            $result = substr($result, 0, $inString[1]) . $newIn . substr($result, $inString[1] + mb_strlen($inString[0]));
        }

        return $result;
    }
}