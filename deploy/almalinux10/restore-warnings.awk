# The legacy dump toggles SQL_NOTES in executable version comments. Retain all
# messages in restore.log, but this one deprecation does not change restored data.
/^Warning \(Code 1287\): '@@sql_notes' is deprecated( and will be removed in a future release\. Please use '@@note_verbosity' instead)?\.?$/ { next }
/^Warning \(Code [0-9]+\)/ { failed = 1 }
END { exit failed ? 1 : 0 }
