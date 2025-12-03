<?php
namespace AutoSyncPro;

if (!defined('ABSPATH')) exit;

class ImportLogger {
    private static $log_file = null;

    public static function get_log_file_path() {
        if (self::$log_file === null) {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/auto-sync-pro-logs';
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
                // Add index.php for security
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
            }
            self::$log_file = $log_dir . '/imported-urls.txt';
        }
        return self::$log_file;
    }

    public static function is_slug_imported($slug, $source_url) {
        $index_file = self::get_log_file_path() . '.index';

        // Check index file first for better performance
        if (file_exists($index_file)) {
            $identifier = self::create_identifier($slug, $source_url);
            $handle = fopen($index_file, 'r');
            if (!$handle) {
                return false;
            }

            while (($line = fgets($handle)) !== false) {
                if (trim($line) === $identifier) {
                    fclose($handle);
                    return true;
                }
            }
            fclose($handle);
        }

        return false;
    }

    public static function log_import($slug, $source_url, $post_id, $title = '') {
        $log_file = self::get_log_file_path();
        $identifier = self::create_identifier($slug, $source_url);

        $timestamp = current_time('mysql');
        $log_entry = sprintf(
            "[%s] ID:%d | Slug:%s | Source:%s | Title:%s\n",
            $timestamp,
            $post_id,
            $slug,
            $source_url,
            $title
        );

        // Append to log file
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

        // Also maintain a simple index for quick checking
        $index_file = self::get_log_file_path() . '.index';
        file_put_contents($index_file, $identifier . "\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    private static function create_identifier($slug, $source_url) {
        return md5($slug . '|' . $source_url) . '::' . $slug . '::' . $source_url;
    }

    public static function get_all_logs($limit = 500, $offset = 0) {
        $log_file = self::get_log_file_path();
        if (!file_exists($log_file)) {
            return [];
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        // Reverse to show newest first
        $lines = array_reverse($lines);

        // Apply pagination
        $lines = array_slice($lines, $offset, $limit);

        $logs = [];
        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\]\s+ID:(\d+)\s+\|\s+Slug:(.*?)\s+\|\s+Source:(.*?)\s+\|\s+Title:(.*)/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'post_id' => intval($matches[2]),
                    'slug' => trim($matches[3]),
                    'source' => trim($matches[4]),
                    'title' => trim($matches[5]),
                ];
            }
        }

        return $logs;
    }

    public static function get_log_count() {
        $log_file = self::get_log_file_path();
        if (!file_exists($log_file)) {
            return 0;
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines ? count($lines) : 0;
    }

    public static function clear_logs() {
        $log_file = self::get_log_file_path();
        $index_file = $log_file . '.index';

        $cleared = false;

        if (file_exists($log_file)) {
            unlink($log_file);
            $cleared = true;
        }

        if (file_exists($index_file)) {
            unlink($index_file);
        }

        return $cleared;
    }

    public static function get_log_file_size() {
        $log_file = self::get_log_file_path();
        if (!file_exists($log_file)) {
            return 0;
        }
        return filesize($log_file);
    }

    public static function format_file_size($bytes) {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
