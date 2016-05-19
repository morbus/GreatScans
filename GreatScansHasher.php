<?php

/**
 * @file
 * GreatScansHasher.php
 *
 * Create and update the database of known scans.
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

set_time_limit(0);

$allowed_file_extensions = ['cbr', 'cbz', 'pdf', 'rar', 'zip'];

$database_path = './data/database.json';
$database_min_path = './data/database.min.json';
$database = json_decode(file_get_contents($database_path));

$database->info = [
  'schema_version' => 1,
  'updated' => time(),
];

$directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]));

foreach ($directory as $file) {
  // Skip non-files or those don't match our allowed extensions.
  if (!$file->isFile() || !in_array($file->getExtension(), $allowed_file_extensions)) {
    continue;
  }

  // Generate the hash and parse the name for metadata.
  $hash = hash('sha256', file_get_contents($file->getPathname()));
  $existing_data = isset($database->scans->$hash) ? $database->scans->$hash : array();
  $database->scans->$hash = (object) array_merge((array) $existing_data, (array) parse_filename($file));
  $database->scans->$hash->hash = $hash;
};

// Sort the database. Because!
$scans = (array) $database->scans;
uasort($scans, function($a, $b) {
  return strcmp($a->standard_format, $b->standard_format);
});

// Save prettiness and minified.
$database->scans = (object) $scans;
file_put_contents($database_path, json_encode($database, JSON_PRETTY_PRINT));
file_put_contents($database_min_path, json_encode($database));

/**
 * Assumes a GreatScans "standard" filename has been passed in.
 *
 * @param SplFileInfo $file
 *   The $file to parse for discoverable data.
 *
 * @return object
 *   An object full of key values discovered from the filename.
 */
function parse_filename(SplFileInfo $file) {
  $filename_data = (object) [
    'standard_format' => $file->getBasename(),
    'ext'             => $file->getExtension(),
    'name'            => NULL,
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

  // Find known tags.
  preg_match('/' . tags_regex() . '/', $filename, $tag_matches);
  $filename_data->tag = isset($tag_matches[1]) ? $tag_matches[1] : NULL;
  $filename = remove_match_from_filename($tag_matches, $filename);

  // Find release dates (YYYY-MM-DD).
  preg_match('/\(((\d{4})-?(\d{2})?-?(\d{2})?)\)/', $filename, $date_matches);
  if (isset($date_matches[0])) {
    $filename_data->date = isset($date_matches[1]) ? $date_matches[1] : NULL;
    $filename_data->year = isset($date_matches[2]) ? $date_matches[2] : NULL;
    $filename_data->month = isset($date_matches[3]) ? $date_matches[3] : NULL;
    $filename_data->day = isset($date_matches[4]) ? $date_matches[4] : NULL;
    $filename = remove_match_from_filename($date_matches, $filename);
  }

  // Find seasonal dates (YYYY-Spring).
  preg_match('/\(((\d{4})-?(\w+))\)/', $filename, $seasonal_matches);
  if (isset($seasonal_matches[0])) {
    $filename_data->date = isset($seasonal_matches[1]) ? $seasonal_matches[1] : NULL;
    $filename_data->year = isset($seasonal_matches[2]) ? $seasonal_matches[2] : NULL;
    $filename_data->month = isset($seasonal_matches[3]) ? $seasonal_matches[3] : NULL;
    $filename = remove_match_from_filename($seasonal_matches, $filename);
  }

  // Find volumes and issues (v#n#).
  preg_match('/v(\d{1,3})n(\d{1,2})/', $filename, $release_matches);
  if (isset($release_matches[0])) {
    $filename_data->number = isset($release_matches[0]) ? $release_matches[0] : NULL;
    $filename_data->volume = isset($release_matches[1]) ? $release_matches[1] : NULL;
    $filename_data->issue = isset($release_matches[2]) ? $release_matches[2] : NULL;
    $filename = remove_match_from_filename($release_matches, $filename);
  }

  // Find codes that describe this issue.
  preg_match_all('/(\[.*?\])/', $filename, $code_matches);
  if (isset($code_matches[0])) {
    foreach ($code_matches[1] as $key => $code_match) {
      $filename_data->codes[] = $code_match;
      $code_match = preg_quote($code_match);
      $filename = preg_replace("/\s*${code_match}\s*/", '', $filename);
    }
  }


  // Guess at issue whole numbers.
  preg_match('/\s+(\d+)/', $filename, $whole_number_matches);
  if (isset($whole_number_matches[0])) {
    $filename_data->number = isset($whole_number_matches[1]) ? $whole_number_matches[1] : NULL;
    $filename_data->whole_number = isset($whole_number_matches[1]) ? $whole_number_matches[1] : NULL;
    $filename = remove_match_from_filename($whole_number_matches, $filename);
  }

  // If all three numbers exist, merge 'em together for number.
  if (isset($filename_data->volume) && isset($filename_data->issue) && isset($filename_data->whole_number)) {
    $filename_data->number = $filename_data->number . ' v'. $filename_data->volume . 'n' . $filename_data->issue;
  }

  // What's left is the name.
  $filename_data->name = $filename;

  print_r($filename_data);

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
    $matched = preg_quote($matches[0]);
    $filename = preg_replace("/\s*${matched}\s*/", '', $filename);
  }

  return $filename;
}

/**
 * Returns a regex-ready list of tags for finding in a filename.
 */
function tags_regex() {
  $tags = explode("\n", file_get_contents('./data/tags.txt'));

  $tags = array_map(function($value) {
    return preg_quote($value, '/');
  }, $tags);

  $tags = implode('|', $tags);
  return "\((${tags})\)";
}

