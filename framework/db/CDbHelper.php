<?php

declare(strict_types=1);

/**
 * Хэлпер для работы с sql запросами
 */
class CDbHelper
{
    /** @var int Минимальная длина sql запроса для сокращения */
    const MIN_SQL_LENGTH_FOR_SHORTEN = 50;

    /** @var int Минимальное количество значений в IN для сокращения */
    const MIN_VALUES_FOR_SHORTEN = 13;

    /** @var int Максимальный размер SQL для INSERT/REPLACE и части SET для UPDATE в байтах */
    const MAX_LOG_SQL_LENGTH = 1024;

    /**
     * Сокращает SQL для логов, сохраняя условие UPDATE и ограничивая объём записываемых значений.
     */
    public static function shortenForLogging(string $sql): string
    {
        $sql = self::shortenMultipleInClauses($sql);

        if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $sql) === 1) {
            return strlen($sql) > self::MAX_LOG_SQL_LENGTH
                ? mb_strcut($sql, 0, self::MAX_LOG_SQL_LENGTH, 'UTF-8') . '...'
                : $sql;
        }

        if (preg_match('/^\s*UPDATE\b/i', $sql) !== 1) {
            return $sql;
        }

        // Игнорируем ключевые слова внутри строк, идентификаторов, комментариев и подзапросов.
        $pattern = <<<'REGEX'
            ~'(?:\\.|''|[^'\\]++)*+'|"(?:\\.|""|[^"\\]++)*+"|`(?:``|[^`]++)*+`|/\*.*?\*/|--[^\r\n]*|\#[^\r\n]*|[()]|\b(?:SET|WHERE|ORDER\s+BY|LIMIT)\b~is
REGEX;
        preg_match_all($pattern, $sql, $tokens, PREG_OFFSET_CAPTURE);
        $depth = 0;
        $setStart = null;
        $setEnd = strlen($sql);
        foreach ($tokens[0] as $item) {
            list($token, $offset) = $item;

            if ($token === '(') {
                ++$depth;
            } elseif ($token === ')') {
                --$depth;
            } elseif ($depth === 0 && strcasecmp($token, 'SET') === 0 && $setStart === null) {
                $setStart = $offset + strlen($token);
            } elseif ($depth === 0 && $setStart !== null && preg_match('/^(?:WHERE|ORDER\s+BY|LIMIT)$/i', $token) === 1) {
                $setEnd = $offset;
                break;
            }
        }

        if ($setStart === null || $setEnd - $setStart <= self::MAX_LOG_SQL_LENGTH) {
            return $sql;
        }

        return substr($sql, 0, $setStart)
            . mb_strcut(substr($sql, $setStart, $setEnd - $setStart), 0, self::MAX_LOG_SQL_LENGTH, 'UTF-8')
            . '...'
            . ($setEnd < strlen($sql) ? ' ' . substr($sql, $setEnd) : '');
    }

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
