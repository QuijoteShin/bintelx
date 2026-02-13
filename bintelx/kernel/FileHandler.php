<?php
# bintelx/kernel/FileHandler.php
# DEPRECATED - Use bX\FileService classes instead
# This file kept for backwards compatibility, redirects to new implementation

namespace bX;

trigger_error('FileHandler is deprecated. Use bX\FileService\Storage, Upload, Document instead.', E_USER_DEPRECATED);

# Re-export main class for legacy code
class_alias('bX\FileService\Storage', 'FileHandler');
