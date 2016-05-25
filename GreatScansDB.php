#!/usr/bin/php
<?php

/**
 * @file
 * GreatScansDB.php
 *
 * Creates and maintains the databases of known scans.
 * Copyright (C) 2016 Morbus Iff <morbus@disobey.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

$documentation = <<<HELP
Usage: $argv[0] --src=/path/to/directory

HELP;

$passed_opts = getopt('h::', ['help::', 'src:']);
if (empty($passed_opts['src']) || isset($passed_opts['h']) || isset($passed_opts['help'])) {
  exit($documentation);
}

// Open the database and prepare common queries.
$dbh = new PDO('sqlite:./data/database.sqlite3')
  or exit('Error opening the database. Contact Morbus Iff.');
$select_file_sth = sql_prepare_select_file($dbh);
$insert_file_sth = sql_prepare_insert_file($dbh);
$update_file_sth = sql_prepare_update_file($dbh);

// For each directory, find, hash, and metadata files.
$file_extensions = ['cbr', 'cbz', 'pdf', 'rar', 'zip'];
foreach (glob($passed_opts['src']) as $passed_dir) {
  if (!is_dir($passed_dir)) {
    continue;
  }

  $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($passed_dir));

  foreach ($dir as $file) {
    if (!$file->isFile() || !in_array($file->getExtension(), $file_extensions)) {
      continue;
    }

    // There are two primary IDs we use for files: size and sha256. Hashing
    // large files with any algorithm is very slow over time, so we first
    // lookup by size: if there's only one result, we'll trust the match.
    // If there are multiple results, we'll lookup again by sha256.
    $select_file_sth->execute([':size' => $file->getSize()]);
    $select_file_results = $select_file_sth->fetchAll(PDO::FETCH_ASSOC);

    $existing_file = [];
    if (count($select_file_results) == 1) {
      $existing_file = $select_file_results[0];
    }
    elseif (count($select_file_results) > 1) {
      $sha256 = hash_file('sha256', $file->getPathname());
      $select_file_sth->execute([':sha256' => $sha256]);
      $select_file_results = $select_file_sth->fetchAll(PDO::FETCH_ASSOC);
      print " • Size clash found: " . $file->getSize() . ". Using sha256 instead.\n";

      if (count($select_file_results) == 1) {
        $existing_file = $select_file_results[0];
      }
    }

    // Discover what we can based on the filename.
    $parsed_filename_data = parse_filename($file);

    // Support multiple codes by serializing.
    if (count($parsed_filename_data['codes'])) {
      $parsed_filename_data['codes'] = serialize($parsed_filename_data['codes']);
    }

    // Add a new file...
    if (empty($existing_file)) {
      $insert_file = [
        'sha256' => hash_file('sha256', $file->getPathname()),
        'size'   => $file->getSize(),
      ] + array_merge($parsed_filename_data);

      $insert_file_sth->execute(sql_get_value_array($insert_file));
      print " • Adding new file ($parsed_filename_data[name]): " . $file->getBasename() . "\n";
    }

    // ...Or tweak an existing.
    if (!empty($existing_file)) {
      $update_file = array_merge($existing_file, $parsed_filename_data);
      $update_file_sth->execute(sql_get_value_array($update_file));
      print " • Updating existing file ($parsed_filename_data[name]): " . $file->getBasename() . "\n";
    }
  };
}

// With the parsing complete, we'll generate the other versions of the
// database that are either more readable, more parsable, or just useful.
$files = $dbh->query('SELECT * FROM files ORDER BY standard_format')->fetchAll(PDO::FETCH_ASSOC);

$sha256sums_fp = fopen('./data/SHA256SUMS.txt', 'w');
$size12sums_fp = fopen('./data/SIZE12SUMS.txt', 'w');
$json = ['files' => []];

foreach ($files as $file) {
  fwrite($sha256sums_fp, $file['sha256'] . " " . $file['standard_format'] . "\n");
  fwrite($size12sums_fp, str_pad($file['size'], 12, 0, STR_PAD_LEFT) . " " . $file['standard_format'] . "\n");
  $json['files'][$file['sha256']] = array_filter($file);
}

file_put_contents('./data/database.json', json_encode($json, JSON_PRETTY_PRINT));
file_put_contents('./data/database.min.json', json_encode($json));

exit;

/**
 * Assumes a GreatScans "standard" filename has been passed in.
 *
 * @param SplFileInfo $file
 *   The $file to parse for discoverable data.
 *
 * @return array
 *   An array of key values pairs discovered from the filename.
 */
function parse_filename(SplFileInfo $file) {
  $filename_data = [
    'standard_format' => $file->getBasename(),
    'ext'             => $file->getExtension(),
    'name'            => NULL,
    'actual_name'     => NULL,
    'number'          => NULL,
    'whole_number'    => NULL,
    'volume'          => NULL,
    'issue'           => NULL,
    'date'            => NULL,
    'year'            => NULL,
    'month'           => NULL,
    'day'             => NULL,
    'tag'             => NULL,
    'codes'           => NULL,
  ];

  // Remove the file extension.
  $filename = str_replace('.' . $file->getExtension(), '', $file->getBasename());

  // Find middle months (1999-09-Mid).
  preg_match('/\(((\d{4})-(\d{2}-Mid))\)/', $filename, $mid_month_matches);
  if (isset($mid_month_matches[0])) {
    $filename_data['date'] = isset($mid_month_matches[1]) ? $mid_month_matches[1] : NULL;
    $filename_data['year'] = isset($mid_month_matches[2]) ? $mid_month_matches[2] : NULL;
    $filename_data['month'] = isset($mid_month_matches[3]) ? $mid_month_matches[3] : NULL;
    $filename = remove_match_from_filename($mid_month_matches, $filename);
  }

  // Find double months (1999-09+10).
  preg_match('/\(((\d{4})-(\d{2}\+\d{2}))\)/', $filename, $dbl_month_matches);
  if (isset($dbl_month_matches[0])) {
    $filename_data['date'] = isset($dbl_month_matches[1]) ? $dbl_month_matches[1] : NULL;
    $filename_data['year'] = isset($dbl_month_matches[2]) ? $dbl_month_matches[2] : NULL;
    $filename_data['month'] = isset($dbl_month_matches[3]) ? $dbl_month_matches[3] : NULL;
    $filename = remove_match_from_filename($dbl_month_matches, $filename);
  }

  // Find release dates (1999, 1999-09, or 1999-09-09).
  preg_match('/\(((\d{4})-?(\d{2})?-?(\d{2})?)\)/', $filename, $date_matches);
  if (isset($date_matches[0])) {
    $filename_data['date'] = isset($date_matches[1]) ? $date_matches[1] : NULL;
    $filename_data['year'] = isset($date_matches[2]) ? $date_matches[2] : NULL;
    $filename_data['month'] = isset($date_matches[3]) ? $date_matches[3] : NULL;
    $filename_data['day'] = isset($date_matches[4]) ? $date_matches[4] : NULL;
    $filename = remove_match_from_filename($date_matches, $filename);
  }

  // Find seasonal dates (1999-Spring).
  preg_match('/\(((\d{4})-?([\w\+]+))\)/', $filename, $seasonal_matches);
  if (isset($seasonal_matches[0])) {
    $filename_data['date'] = isset($seasonal_matches[1]) ? $seasonal_matches[1] : NULL;
    $filename_data['year'] = isset($seasonal_matches[2]) ? $seasonal_matches[2] : NULL;
    $filename_data['month'] = isset($seasonal_matches[3]) ? $seasonal_matches[3] : NULL;
    $filename = remove_match_from_filename($seasonal_matches, $filename);
  }

  // Find volumes and issues (v19n9 or v19n9+10).
  preg_match('/v(\d{1,3})n(\d{1,3}(\+\d{1,3})?)/', $filename, $release_matches);
  if (isset($release_matches[0])) {
    $filename_data['number'] = isset($release_matches[0]) ? $release_matches[0] : NULL;
    $filename_data['volume'] = isset($release_matches[1]) ? $release_matches[1] : NULL;
    $filename_data['issue'] = isset($release_matches[2]) ? $release_matches[2] : NULL;
    $filename = remove_match_from_filename($release_matches, $filename);
  }

  // Find codes that describe this issue ([a1], [fixed], etc.).
  preg_match_all('/(\[.*?\])/', $filename, $code_matches);
  if (isset($code_matches[0])) {
    foreach ($code_matches[1] as $code_match) {
      $filename_data['codes'][] = $code_match;
      $filename = remove_match_from_filename([$code_match], $filename);
    }
  }

  // Find double issue numbers (19+20).
  preg_match('/\s+(\d+\+\d+)/', $filename, $dbl_number_matches);
  if (isset($dbl_number_matches[0])) {
    $filename_data['number'] = isset($dbl_number_matches[1]) ? $dbl_number_matches[1] : NULL;
    $filename_data['whole_number'] = isset($dbl_number_matches[1]) ? $dbl_number_matches[1] : NULL;
    $filename = remove_match_from_filename($dbl_number_matches, $filename);
  }

  // Find issue numbers (any remaining numbers left).
  preg_match('/\s+(\d+)/', $filename, $whole_number_matches);
  if (isset($whole_number_matches[0])) {
    $filename_data['number'] = isset($whole_number_matches[1]) ? $whole_number_matches[1] : NULL;
    $filename_data['whole_number'] = isset($whole_number_matches[1]) ? $whole_number_matches[1] : NULL;
    $filename = remove_match_from_filename($whole_number_matches, $filename);
  }

  // If all three issue numbers exist, merge together for the standard format.
  if (isset($filename_data['volume']) && isset($filename_data['issue']) && isset($filename_data['whole_number'])) {
    $filename_data['number'] = $filename_data['number'] . ' v' . $filename_data['volume'] . 'n' . $filename_data['issue'];
  }

  // Anything else in parenthesis is a scanner tag...
  preg_match('/\((.[^\(]*)\)$/', $filename, $tag_matches);
  if (isset($tag_matches[0])) {
    $filename_data['tag'] = isset($tag_matches[1]) ? $tag_matches[1] : NULL;
    $filename = remove_match_from_filename($tag_matches, $filename);
  }

  // ...Unless it's a known country abbreviation.
  if (in_array($filename_data['tag'], ['AU', 'UK'])) {
    $filename .= ' (' . $filename_data['tag'] . ')';
    $filename_data['tag'] = NULL;
  }

  // Anything left is the title.
  $filename_data['name'] = $filename;

  return $filename_data;
}

/**
 * Remove a matched regexp from the filename.
 *
 * @param array $matches
 *   The $matches array filled by a preg_replace().
 * @param string $filename
 *   The $filename to remove any matches from.
 *
 * @return string
 *   The $filename with any $matches removed.
 */
function remove_match_from_filename(array $matches, $filename) {
  if (isset($matches[0])) {
    $filename = preg_replace('/\s*' . preg_quote($matches[0]) . '\s*/', '', $filename);
  }

  return $filename;
}

/**
 * Prepare the "select file based on sha256 or size" SQL query.
 *
 * @param PDO $dbh
 *   The PDO database handler.
 *
 * @return PDOStatement
 *   A PDO statement handler representing the prepared statement.
 */
function sql_prepare_select_file(PDO $dbh) {
  return $dbh->prepare('SELECT * FROM files WHERE (sha256 = :sha256 OR size = :size)');
}

/**
 * Prepare the "insert file data" SQL query.
 *
 * @param PDO $dbh
 *   The PDO database handler.
 *
 * @return PDOStatement
 *   A PDO statement handler representing the prepared statement.
 */
function sql_prepare_insert_file(PDO $dbh) {
  return $dbh->prepare('INSERT INTO files (sha256, size, standard_format,
    ext, name, actual_name, number, whole_number, volume, issue, date, year,
    month, day, tag, codes) VALUES (:sha256, :size, :standard_format, :ext,
    :name, :actual_name, :number, :whole_number, :volume, :issue, :date, :year,
    :month, :day, :tag, :codes)');
}

/**
 * Prepare the "update file data" SQL query.
 *
 * @param PDO $dbh
 *   The PDO database handler.
 *
 * @return PDOStatement
 *   A PDO statement handler representing the prepared statement.
 */
function sql_prepare_update_file(PDO $dbh) {
  return $dbh->prepare('UPDATE files SET sha256 = :sha256, size = :size,
    standard_format = :standard_format, ext = :ext, name = :name, actual_name
    = :actual_name, number = :number, whole_number = :whole_number, volume =
    :volume, issue = :issue, date = :date, year = :year, month = :month,
    day = :day, tag = :tag, codes = :codes WHERE sha256 = :sha256');
}

/**
 * Turn the passed array into a value array for our prepared statements.
 *
 * @param array $array
 *   An array with key-value pairs.
 *
 * @return array
 *   An array with all keys prefixed with a ":".
 */
function sql_get_value_array(array $array) {
  foreach ($array as $key => $value) {
    $array[':' . $key] = $value;
    unset($array[$key]);
  }

  return $array;
}
