
# GreatScans

## The database

Describe the fields here.

### SQLite3

Something there.

    CREATE TABLE files (
      sha256 TEXT PRIMARY KEY NOT NULL,
      size INTEGER NOT NULL,
      standard_format TEXT NOT NULL,
      ext TEXT NOT NULL,
      name TEXT NOT NULL,
      actual_name TEXT,
      number TEXT,
      whole_number TEXT,
      volume TEXT,
      issue TEXT,
      date TEXT,
      year TEXT,
      month TEXT,
      day TEXT,
      tag TEXT,
      codes TEXT
    );
    CREATE UNIQUE INDEX files_sha256_uindex ON files (sha256);
    CREATE INDEX files_size_index ON files(size);

Something here.
