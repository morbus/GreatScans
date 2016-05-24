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
Usage: $argv[0] --srcdir=/path/to/directory

HELP;

$passed_opts = getopt('h::', ['help::', 'srcdir:']);
if (empty($passed_opts['srcdir']) || isset($passed_opts['h']) || isset($passed_opts['help'])) {
  exit($documentation);
}

$checksums_path = './data/SHA256SUMS.txt';
$database_path = './data/database.json';
$database_min_path = './data/database.min.json';
$file_extensions = ['cbr', 'cbz', 'pdf', 'rar', 'zip'];

$database = json_decode(file_get_contents($database_min_path));

$database->info = [
  'schema_version' => 1,
  'updated' => time(),
];

// For each directory, find, hash, and metadata files.
foreach (glob($passed_opts['srcdir']) as $passed_dir) {
  if (!is_dir($passed_dir)) {
    continue;
  }

  $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($passed_dir));

  foreach ($dir as $file) {
    if (!$file->isFile() || !in_array($file->getExtension(), $file_extensions)) {
      continue;
    }

    // Generate the hash and parse the name for metadata.
    $hash = hash('sha256', file_get_contents($file->getPathname()));
    $existing_data = isset($database->scans->$hash) ? $database->scans->$hash : array();
    $database->scans->$hash = (object) array_merge((array) $existing_data, (array) parse_filename($file));
    $database->scans->$hash->hash = $hash;

    // @todo add "reparse" option for existing hashes?
    // @todo only show parse data if new hash.
    // @todo Write up documentation on DB format.
    // @todo Remove duplicate hash in the data array.
    // @todo For searching, let's switch to sqlite.
    // @todo spit list of titles to ease parse error discovery.
    // @todo Show entries with a missing month?
  };
}

// Sort the database. Because!
$scans = (array) $database->scans;
uasort($scans, function($a, $b) {
  return strcmp($a->standard_format, $b->standard_format);
});

$database->scans = (object) $scans;

// Do some final cleanup on the whole database, such as removing NULL values
// (which reduced JSON file sizes by 12-15% in early tests). While we're
// looping through the whole thing, we'll also create a CHECKSUMS file which
// will double as an easy-to-read file listing of Every Known Scan.
$checksums_fp = fopen($checksums_path, 'w');
foreach ($database->scans as $hash => $data) {
  $database->scans->$hash = (object) array_filter((array) $data);
  fwrite($checksums_fp, $hash . " " . $data->standard_format . "\n");
}

// Save the JSON databases.
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

  // Find middle months (1999-09-Mid).
  preg_match('/\(((\d{4})-(\d{2}-Mid))\)/', $filename, $mid_month_matches);
  if (isset($mid_month_matches[0])) {
    $filename_data->date = isset($mid_month_matches[1]) ? $mid_month_matches[1] : NULL;
    $filename_data->year = isset($mid_month_matches[2]) ? $mid_month_matches[2] : NULL;
    $filename_data->month = isset($mid_month_matches[3]) ? $mid_month_matches[3] : NULL;
    $filename = remove_match_from_filename($mid_month_matches, $filename);
  }

  // Find release dates (1999, 1999-09, or 1999-09-09).
  preg_match('/\(((\d{4})-?(\d{2})?-?(\d{2})?)\)/', $filename, $date_matches);
  if (isset($date_matches[0])) {
    $filename_data->date = isset($date_matches[1]) ? $date_matches[1] : NULL;
    $filename_data->year = isset($date_matches[2]) ? $date_matches[2] : NULL;
    $filename_data->month = isset($date_matches[3]) ? $date_matches[3] : NULL;
    $filename_data->day = isset($date_matches[4]) ? $date_matches[4] : NULL;
    $filename = remove_match_from_filename($date_matches, $filename);
  }

  // Find double months (1999-09+10).
  preg_match('/\(((\d{4})-(\d{2}\+\d{2}))\)/', $filename, $dbl_month_matches);
  if (isset($dbl_month_matches[0])) {
    $filename_data->date = isset($dbl_month_matches[1]) ? $dbl_month_matches[1] : NULL;
    $filename_data->year = isset($dbl_month_matches[2]) ? $dbl_month_matches[2] : NULL;
    $filename_data->month = isset($dbl_month_matches[3]) ? $dbl_month_matches[3] : NULL;
    $filename = remove_match_from_filename($dbl_month_matches, $filename);
  }

  // Find seasonal dates (1999-Spring).
  preg_match('/\(((\d{4})-?([\w\+]+))\)/', $filename, $seasonal_matches);
  if (isset($seasonal_matches[0])) {
    $filename_data->date = isset($seasonal_matches[1]) ? $seasonal_matches[1] : NULL;
    $filename_data->year = isset($seasonal_matches[2]) ? $seasonal_matches[2] : NULL;
    $filename_data->month = isset($seasonal_matches[3]) ? $seasonal_matches[3] : NULL;
    $filename = remove_match_from_filename($seasonal_matches, $filename);
  }

  // Find volumes and issues (v19n9 or v19n9+10).
  preg_match('/v(\d{1,3})n(\d{1,3}(\+\d{1,3})?)/', $filename, $release_matches);
  if (isset($release_matches[0])) {
    $filename_data->number = isset($release_matches[0]) ? $release_matches[0] : NULL;
    $filename_data->volume = isset($release_matches[1]) ? $release_matches[1] : NULL;
    $filename_data->issue = isset($release_matches[2]) ? $release_matches[2] : NULL;
    $filename = remove_match_from_filename($release_matches, $filename);
  }

  // Find codes that describe this issue ([b], [f], etc.).
  preg_match_all('/(\[.*?\])/', $filename, $code_matches);
  if (isset($code_matches[0])) {
    foreach ($code_matches[1] as $key => $code_match) {
      $filename_data->codes[] = $code_match;
      $filename = remove_match_from_filename(array($code_match), $filename);
    }
  }

  // Find double issue numbers (19+20).
  preg_match('/\s+(\d+\+\d+)/', $filename, $double_number_matches);
  if (isset($double_number_matches[0])) {
    $filename_data->number = isset($double_number_matches[1]) ? $double_number_matches[1] : NULL;
    $filename_data->whole_number = isset($double_number_matches[1]) ? $double_number_matches[1] : NULL;
    $filename = remove_match_from_filename($double_number_matches, $filename);
  }

  // Guess at issue whole numbers (any remaining numbers left)
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

  // Anything else in parenthesis is a scanner tag.
  preg_match('/\s*\((.*)\)/', $filename, $tag_matches);
  if (isset($tag_matches[0])) {
    $filename_data->tag = isset($tag_matches[1]) ? $tag_matches[1] : NULL;
    $filename = remove_match_from_filename($tag_matches, $filename);
  }

  // And what's left is the title.
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
