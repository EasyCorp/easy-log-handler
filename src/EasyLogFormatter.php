<?php

namespace EasyCorp\EasyLog;

use Monolog\Formatter\FormatterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * This formatter is specially designed to make logs more human-friendly in the
 * development environment. It takes all the log records and processed them in
 * batch to perform advanced tasks (such as combining similar consecutive logs).
 *
 */
class EasyLogFormatter implements FormatterInterface
{
    private $maxLineLength = 100;
    private $prefixLength = 2;

    public function format(array $record)
    {
        throw new \RuntimeException('The method "format()" should never be called (call "formatBatch()" instead).  Please read EasyLogHandler README instructions to learn how to configure and use it.');
    }

    public function formatBatch(array $records)
    {
        $logBatch = array('formatted' => '');

        // ignore the logs related to the web debug toolbar
        if ($this->isWebDebugToolbarLog($records)) {
            return $logBatch;
        }

        $logBatch['formatted'] .= $this->formatLogBatchHeader($records);

        foreach ($records as $key => $record) {
            if ($this->isDeprecationLog($record)) {
                $records[$key] = $this->processDeprecationLogRecord($record);
            }

            if ($this->isEventStopLog($record)) {
                $records[$key] = $this->processEventStopLogRecord($record);
            }

            if ($this->isEventNotificationLog($record)) {
                $records[$key] = $this->processEventNotificationLogRecord($records, $key);
            }

            if ($this->isTranslationLog($record)) {
                $records[$key] = $this->processTranslationLogRecord($records, $key);
            }

            if ($this->isRouteMatchLog($record)) {
                $records[$key] = $this->processRouteMatchLogRecord($record);
            }

            if ($this->isDoctrineLog($record)) {
                $records[$key] = $this->processDoctrineLogRecord($record);
            }

            $logBatch['formatted'] .= rtrim($this->formatRecord($records, $key), PHP_EOL).PHP_EOL;
        }

        $logBatch['formatted'] .= PHP_EOL.PHP_EOL;

        return $logBatch;
    }

    private function isAsseticLog($record)
    {
        return isset($record['context']['route']) && 0 === strpos($record['context']['route'], '_assetic_');
    }

    private function isDeprecationLog($record)
    {
        $isPhpChannel = 'php' === $record['channel'];
        $isDeprecationError = isset($record['context']['type']) && E_USER_DEPRECATED === $record['context']['type'];
        $looksLikeDeprecationMessage = false !== strpos($record['message'], 'deprecated since');

        return $isPhpChannel && ($isDeprecationError || $looksLikeDeprecationMessage);
    }

    private function isDoctrineLog($record)
    {
        return isset($record['channel']) && 'doctrine' === $record['channel'];
    }

    private function isEventStopLog($record)
    {
        return 'Listener "{listener}" stopped propagation of the event "{event}".' === $record['message'];
    }

    private function isEventNotificationLog($record)
    {
        $isEventNotifyChannel = isset($record['channel']) && '_event_notify' === $record['channel'];
        $isEventChannel = isset($record['channel']) && 'event' === $record['channel'];
        $contextIncludesEventNotification = isset($record['context']) && isset($record['context']['event']) && isset($record['context']['listener']);

        return $isEventNotifyChannel || ($isEventChannel && $contextIncludesEventNotification);
    }

    private function isRouteMatchLog($record)
    {
        return 'Matched route "{route}".' === $record['message'];
    }

    private function isTranslationLog($record) {
        return isset($record['channel']) && 'translation' === $record['channel'];
    }

    private function isWebDebugToolbarLog(array $records)
    {
        foreach ($records as $record) {
            if (isset($record['context']['route']) && '_wdt' === $record['context']['route']) {
                return true;
            }
        }

        return false;
    }

    private function processDeprecationLogRecord($record)
    {
        $context = $record['context'];

        unset($context['type']);
        unset($context['level']);

        $record['context'] = $context;

        return $record;
    }

    private function processDoctrineLogRecord($record)
    {
        if (!isset($record['context']) || empty($record['context'])) {
            return $record;
        }

        $isDatabaseQueryContext = $this->arrayContainsOnlyNumericKeys($record['context']);
        if ($isDatabaseQueryContext) {
            $record['context'] = array('query params' => $record['context']);
        }

        return $record;
    }

    /**
     * In Symfony applications is common to have lots of consecutive "event notify"
     * log messages. This method combines them all to generate a more compact output.
     *
     * @param array $records
     * @param int   $currentRecordIndex
     * @return string
     */
    private function processEventNotificationLogRecord(array $records, $currentRecordIndex)
    {
        $record = $records[$currentRecordIndex];

        $record['message'] = null;
        $record['channel'] = '_event_notify';
        $record['context'] = array($record['context']['event'] => $record['context']['listener']);

        // if the previous record is also an event notification, combine them
        if (isset($records[$currentRecordIndex - 1]) && $this->isEventNotificationLog($records[$currentRecordIndex - 1])) {
            $record['_properties']['display_log_info'] = false;
        }

        return $record;
    }

    private function processEventStopLogRecord($record)
    {
        $record['channel'] = '_event_stop';
        $record['message'] = 'Event "{event}" stopped by:';

        return $record;
    }

    private function processRouteMatchLogRecord($record)
    {
        if ($this->isAsseticLog($record)) {
            $record['message'] = '{method}: {request_uri}';

            return $record;
        }

        $context = $record['context'];

        unset($context['method']);
        unset($context['request_uri']);

        $record['context'] = array_merge(
            array($record['context']['method'] => $record['context']['request_uri']),
            $context
        );

        return $record;
    }

    /**
     * It interpolates the given string replacing its placeholders with the
     * values defined in the given variables array.
     *
     * @param string $string
     * @param array  $variables
     * @return string
     */
    private function processStringPlaceholders($string, $variables)
    {
        foreach ($variables as $key => $value) {
            if (!is_string($value) && !is_numeric($value) && !is_bool($value)) {
                continue;
            }

            $string = str_replace('{'.$key.'}', $value, (string) $string);
        }

        return $string;
    }

    /**
     * In Symfony applications is common to have lots of consecutive "translation not found"
     * log messages. This method combines them all to generate a more compact output.
     *
     * @param array $records
     * @param int   $currentRecordIndex
     * @return string
     */
    private function processTranslationLogRecord(array $records, $currentRecordIndex)
    {
        $record = $records[$currentRecordIndex];

        if (isset($records[$currentRecordIndex - 1]) && $this->isTranslationLog($records[$currentRecordIndex - 1])) {
            $record['_properties']['display_log_info'] = false;
            $record['message'] = null;
        }

        return $record;
    }

    private function formatLogChannel($record)
    {
        if (!isset($record['channel'])) {
            return '';
        }

        if ($this->isDeprecationLog($record)) {
            return '** DEPRECATION **';
        }

        if ($this->isEventNotificationLog($record)) {
            return 'NOTIFIED EVENTS';
        }

        $channel = $record['channel'];
        $channelIcons = array(
            '_event_stop' => '[!] ',
            'security' => '(!) ',
        );
        $channelIcon = array_key_exists($channel, $channelIcons) ? $channelIcons[$channel] : '';

        return sprintf('%s%s', $channelIcon, strtoupper($channel));
    }

    private function formatContext(array $record)
    {
        $context = $this->filterVariablesUsedAsPlaceholders($record['message'], $record['context']);

        $contextAsString = Yaml::dump($context, $this->getInlineLevel($record), $this->prefixLength);
        $contextAsString = $this->formatTextBlock($contextAsString, '--> ');
        $contextAsString = rtrim($contextAsString, PHP_EOL);

        return $contextAsString;
    }

    private function formatExtra(array $record)
    {
        $extraAsString = Yaml::dump(array('extra' => $record['extra']), 1, $this->prefixLength);
        $extraAsString = $this->formatTextBlock($extraAsString, '--> ');

        return $extraAsString;
    }

    private function formatLogInfo($record)
    {
        return sprintf('%s%s', $this->formatLogLevel($record), $this->formatLogChannel($record));
    }

    private function formatLogLevel($record)
    {
        if (!isset($record['level_name'])) {
            return '';
        }

        $level = $record['level_name'];
        $levelLabels = array(
            'DEBUG' => '',
            'INFO' => '',
            'WARNING' => '** WARNING ** ==> ',
            'ERROR' => '*** ERROR *** ==> ',
            'CRITICAL' => '*** CRITICAL ERROR *** ==> ',
        );

        return array_key_exists($level, $levelLabels) ? $levelLabels[$level] : $level. ' ';
    }

    private function formatMessage($record)
    {
        $message = $this->processStringPlaceholders($record['message'], $record['context']);
        $message = $this->formatStringAsTextBlock($message);

        return $message;
    }

    private function formatRecord(array $records, $currentRecordIndex)
    {
        $record = $records[$currentRecordIndex];
        $recordAsString = '';

        if ($this->isLogInfoDisplayed($record)) {
            $logInfo = $this->formatLogInfo($record);
            $recordAsString .= $this->formatAsSection($logInfo);
        }

        if (isset($record['message']) && !empty($record['message'])) {
            $recordAsString .= $this->formatMessage($record).PHP_EOL;
        }

        if (isset($record['context']) && !empty($record['context'])) {
            // if the context contains an error stack trace, remove it to display it separately
            $stack = null;
            if (isset($record['context']['stack'])) {
                $stack = $record['context']['stack'];
                unset($record['context']['stack']);
            }

            $recordAsString .= $this->formatContext($record).PHP_EOL;

            if (null !== $stack) {
                $recordAsString .= '--> Stack Trace:'.PHP_EOL;
                $recordAsString .= $this->formatStackTrace($stack, '    | ');
            }
        }

        if (isset($record['extra']) && !empty($record['extra'])) {
            // don't display the extra information when it's identical to the previous log record
            $previousRecordExtra = isset($records[$currentRecordIndex-1]) ? $records[$currentRecordIndex-1]['extra'] : null;
            if ($record['extra'] !== $previousRecordExtra) {
                $recordAsString .= $this->formatExtra($record).PHP_EOL;
            }
        }

        return $recordAsString;
    }

    private function formatStackTrace($trace, $prefix = '')
    {
        $traceAsString = '';
        foreach ($trace as $line) {
            if (isset($line['class']) && isset($line['type']) && isset($line['function'])) {
                $traceAsString .= sprintf('%s%s%s()', $line['class'], $line['type'], $line['function']).PHP_EOL;
            } elseif (isset($line['class'])) {
                $traceAsString .= sprintf('%s', $line['class']).PHP_EOL;
            } elseif (isset($line['function'])) {
                $traceAsString .= sprintf('%s()', $line['function']).PHP_EOL;
            }

            if (isset($line['file']) && isset($line['line'])) {
                $traceAsString .= sprintf('  > %s:%d', $this->makePathRelative($line['file']), $line['line']).PHP_EOL;
            }
        }

        $traceAsString = $this->formatTextBlock($traceAsString, $prefix, true);

        return $traceAsString;
    }

    private function formatLogBatchHeader(array $records)
    {
        $firstRecord = $records[0];

        if ($this->isAsseticLog($firstRecord)) {
            return $this->formatAsSubtitle('Assetic request');
        }

        $logDate = $firstRecord['datetime'];
        $logDateAsString = $logDate->format('d/M/Y H:i:s');

        return $this->formatAsTitle($logDateAsString);
    }

    private function formatAsTitle($text)
    {
        $titleLines = array();

        $titleLines[] = str_repeat('#', $this->maxLineLength);
        $titleLines[] = rtrim($this->formatAsSubtitle($text), PHP_EOL);
        $titleLines[] = str_repeat('#', $this->maxLineLength);

        return implode(PHP_EOL, $titleLines).PHP_EOL;
    }

    private function formatAsSubtitle($text)
    {
        $subtitle = str_pad('###  '.$text.'  ', $this->maxLineLength, '#', STR_PAD_BOTH);

        return $subtitle.PHP_EOL;
    }

    private function formatAsSection($text)
    {
        $section = str_pad(str_repeat('_', 3).' '.$text.' ', $this->maxLineLength, '_', STR_PAD_RIGHT);

        return $section.PHP_EOL;
    }

    private function formatStringAsTextBlock($string)
    {
        if (!is_string($string)) {
            return '';
        }

        $string = wordwrap($string, $this->maxLineLength - $this->prefixLength);

        $stringLines = explode(PHP_EOL, $string);
        foreach ($stringLines as &$line) {
            $line = str_repeat(' ', $this->prefixLength).$line;
        }

        $string = implode(PHP_EOL, $stringLines);

        // needed to remove the unnecessary prefix added to the first line
        $string = trim($string);

        return $string;
    }

    /**
     * Prepends the prefix to every line of the given string.
     *
     * @param string $text
     * @param string $prefix
     * @param bool $prefixAllLines If false, prefix is only added to lines that don't start with white spaces
     *
     * @return string
     */
    private function formatTextBlock($text, $prefix = '', $prefixAllLines = false)
    {
        if (empty($text)) {
            return $text;
        }

        $textLines = explode(PHP_EOL, $text);

        // remove the trailing PHP_EOL (and add it back afterwards) to avoid formatting issues
        $addTrailingNewline = false;
        if ('' === $textLines[count($textLines)-1]) {
            array_pop($textLines);
            $addTrailingNewline = true;
        }

        $newTextLines = array();
        foreach ($textLines as $line) {
            if ($prefixAllLines) {
                $newTextLines[] = $prefix.$line;
            } else {
                if (isset($line[0]) && ' ' !== $line[0]) {
                    $newTextLines[] = $prefix.$line;
                } else {
                    $newTextLines[] = str_repeat(' ', strlen($prefix)).$line;
                }
            }
        }

        return implode(PHP_EOL, $newTextLines).($addTrailingNewline ? PHP_EOL : '');
    }

    /**
     * It scans the given string for placeholders and removes from $variables
     * any element whose key matches the name of a placeholder.
     *
     * @param string $string
     * @param array  $variables
     * @return array
     */
    private function filterVariablesUsedAsPlaceholders($string, array $variables)
    {
        if (empty($string)) {
            return $variables;
        }

        return array_filter($variables, function ($key) use ($string) {
            return false === strpos($string, '{'.$key.'}');
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * It returns the level at which YAML component inlines the values, which
     * determines how compact or readable the information is displayed.
     *
     * @param $record
     * @return int
     */
    private function getInlineLevel($record)
    {
        if ($this->isTranslationLog($record)) {
            return 0;
        }

        if ($this->isDoctrineLog($record) || $this->isAsseticLog($record)) {
            return 1;
        }

        return 2;
    }

    /**
     * It returns true when the general information related to the record log
     * should be displayed. It returns false when a log is displayed in a compact
     * way to combine it with a similar previous record.
     *
     * @param $record
     * @return bool
     */
    private function isLogInfoDisplayed($record)
    {
        if (!isset($record['_properties']) || !isset($record['_properties']['display_log_info'])) {
            return true;
        }

        return $record['_properties']['display_log_info'];
    }

    private function arrayContainsOnlyNumericKeys($array)
    {
        return 0 === count(array_filter(array_keys($array), 'is_string'));
    }

    private function makePathRelative($filePath) {
        $thisFilePath = __FILE__;
        $thisFilePathParts = explode('/src/', $thisFilePath);
        $projectRootDir = $thisFilePathParts[0].DIRECTORY_SEPARATOR;

        return str_replace($projectRootDir, '', $filePath);
    }
}
