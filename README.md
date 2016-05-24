
# GreatScans

PROJECT MOTHBALLED.

I’ve been working on this on and off for the past week, refining the database,
adding more issues to it, fixing the metadata parser. Currently, the database
contains 2200 files totaling 150 GBs worth of data.

2200 files is roughly 5% of what’s out there.

And, with this mere 5%, I’ve come to believe that a GoodTools model is not
going to work well. For those 2200 files of about 150 GB, it takes 45 minutes
to hash them, on a computer from late 2013.

Every user who would want to use this script would need to hash.

It would not be a good experience if it took hours upon hours (multiple days
worth for large collections) to do ANYTHING with the script - to analyze, to
move, to validate. Even if there was a cache that made it run faster the next
time around, no one is going to wait for hours to create that cache.

I was using SHA256 as a hashing algorithm, but the speed is about the same for
CRC32: it has very little to do with the algorithm, and everything to do with
the size of the files available.

And, funnily, this probably explains why GoodTools stopped at the N64 (whose
ROMs usually range 6 MB to 20 MB in size.). Disc-based games after that
became, obviously, much bigger in size, thus making the hashing and end-user
friendliness plummet.

This was a fun experiment while it lasted,
but the current approach is dead
in the water.
