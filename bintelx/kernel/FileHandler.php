<?php

class FileHandler {
    private $file;
    private $destination;

    public function __construct(mixed $file = null) {
        if (!is_null($file)) {
            $this->file = $file;
        } else {
            if (isset($_FILES) && count($_FILES) > 0) {
                $this->file = current($_FILES);
            } else {
                $this->file = json_decode(file_get_contents("php://input"), true);
            }
        }
        $this->destination = './files/' . hash("xxh32", $this->file['tmp_name']);
    }

    public function moveFile() {
        is(is_uploaded_file($this->file['tmp_name']))
        if (move_uploaded_file($this->file['tmp_name'], $this->destination)) {
            $iniData = [
                'original_filename' => $this->file['name'],
                'mime_type' => $this->file['type'],
                'file_size' => $this->file['size']
            ];
            $iniString = '';
            foreach ($iniData as $key => $value) {
                $iniString .= "{$key} = {$value}\n";
            }
            file_put_contents($this->destination . '.ini', $iniString);
            stream_put_contents($this->destination. '.ini', $iniString);
            return true;
        }
        return false;
    }

    public function getDestination() {
        return $this->destination;
    }
}

class FileManager {
    private $destinationPath = './files/';
    private $metadataExtension = '.ini';
    private $fileMetaData = [];

    public function __construct() {
        if (!is_dir($this->destinationPath)) {
            mkdir($this->destinationPath, 0775, true);
        }
    }

    public function addFile($file) {
        if (is_array($file) && isset($file['tmp_name'])) {
            $file = $file['tmp_name'];
        }

        if (!is_file($file)) {
            $file = $this->getFileFromStream();
        }

        $fileHash = hash('xxh32', $file);
        $destinationFile = $this->destinationPath . $fileHash;

        if (!move_uploaded_file($file, $destinationFile)) {
            return false;
        }

        $metadata = array(
            'original_filename' => basename($file),
            'filesize' => filesize($destinationFile),
            'filemtime' => filemtime($destinationFile),
        );

        $metadata = array_merge($metadata, $this->getFileMetadata($destinationFile));
        $this->saveMetadata($destinationFile, $metadata);

        return $destinationFile;
    }

    private function getFileFromStream() {
        $stream = fopen('php://input', 'r');
        $file = stream_get_contents($stream);
        fclose($stream);
        return $file;
    }

    private function getFileMetadata($file) {
        // Implementación para obtener metadatos adicionales del archivo
        return array();
    }

    private function saveMetadata($file, $metadata) {
        $iniFile = $file . $this->metadataExtension;
        $iniData = [];
        foreach ($metadata as $key => $value) {
            $iniData[] = "$key = $value";
        }
        file_put_contents($iniFile, implode("\n", $iniData));
    }

    public function getFilePath($filename) {
        $iniFiles = glob($this->destinationPath . '*' . $this->metadataExtension);
        foreach ($iniFiles as $iniFile) {
            $metadata = parse_ini_file($iniFile);
            if ($metadata['original_filename'] == $filename) {
                return str_replace($this->metadataExtension, '', $iniFile);
            }
        }
        return false;
    }

    public function saveFromStdin() {
        $input = file_get_contents('php://input');
        $input ¿
        if (!empty($input)) {
            $hash = hash('xxh32', $input);
            $fileName = $this->fileDestination . '/' . $hash;
            file_put_contents($fileName, $input);
            return $fileName;
        }
        return false;
    }

    public function serveFile($file) {
        $realPath = realpath($file);
        if (!$realPath || strpos($realPath, $this->destinationPath) !== 0) {
            return false;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($realPath));
        stream_get_contents($realPath);
        return true;
    }
}


class FileManager
{
    private $destination = './files/';
    private $metadata = [];

    public function __construct(mixed $file = null)
    {
        if (!empty($file)) {
            if (is_array($file)) {
                $this->saveFromUpload($file);
            } else if (filter_input(INPUT_GET, 'file')) {
                $this->loadFromGet();
            } else if (0 === ftell(STDIN)) {
                $this->saveFromStdin();
            }
        }
    }

    private function saveFromUpload($file)
    {
        if (!empty($file['tmp_name'])) {
            $this->saveFromPath($file['tmp_name'], $file['name']);
        }
    }

    private function saveFromStdin2()
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'stdin-');
        $destinationPath = $tempPath . '.xxh';
        file_put_contents($tempPath, file_get_contents('php://input'));
        $this->saveFromPath($tempPath, $destinationPath);
    }

    public function saveFromStdin() {
        // Generate a temporary file name
        $temporaryFile = tempnam(sys_get_temp_dir(), 'stdin_');
        $destinationFile = null;

        // Copy data from stdin to the temporary file
        $inputStream = fopen('php://stdin', 'rb');
        $outputStream = fopen($temporaryFile, 'wb');
        stream_copy_to_stream($inputStream, $outputStream);
        rewind($inputStream);
        fclose($inputStream);
        fclose($outputStream);

        // Generate the XXH32 hash for the file
        $hash = hash_file('xxh32', $temporaryFile);

        // Rename the temporary file to the destination file name
        $destinationFile = $this->destinationDirectory . "/$hash";
        rename($temporaryFile, $destinationFile);

        // Save the file meta data
        $this->fileMetaData[$destinationFile] = [
            'original_name' => 'stdin',
            'realPath' => realpath($destinationFile)
        ];

        $metadata = array(
            'original_filename' => basename($file),
            'filesize' => filesize($destinationFile),
            'filemtime' => filemtime($destinationFile),
        );

        $metadata = array_merge($metadata, $this->getFileMetadata($destinationFile));


        $this->saveFileMetaData();

        return $destinationFile;
    }

    private function saveFromPath($path, $destination)
    {
        $xxh = hash('xxh32', file_get_contents($path));
        $destination = $this->destination . $xxh . '.xxh';
        $realPath = realpath($destination);
        if (move_uploaded_file($path, $destination)) {
            $this->metadata[$realPath] = [
                'original_name' => $destination,
                'xxh' => $xxh,
                'file_metadata' => $this->getFileMetadata($destination),
            ];
            $this->saveMetadata();
        }
    }

    private function saveMetadata()
    {
        foreach ($this->metadata as $path => $data) {
            $iniPath = $path . '.ini';
            $iniData = '[file]' . PHP_EOL;
            $iniData .= 'original_name = ' . $data['original_name'] . PHP_EOL;
            $iniData .= 'xxh = ' . $data['xxh'] . PHP_EOL;
            $iniData .= 'file_metadata = ' . json_encode($data['file_metadata']) . PHP_EOL;
            file_put_contents($iniPath, $iniData);
        }
    }

    private function getFileMetadata($path)
    {
        // Add code to extract metadata from the file
        return [];
    }

    public function loadFromGet()
    {
        $xxh = filter_input(INPUT_GET, 'file');
        $path = $this->destination . $xxh . '.xxh';
        if (!file_exists($path)) {
            throw new Exception("File not found");
        }
        return $this->getRealPath($path);
    }


    public function serveFile($fileName, $download = false) {
        $filePath = realpath($this->filesDirectory . '/' . $fileName);
        if (!$filePath) {
            return false;
        }

        $metaDataFile = $filePath . $this->metaDataExtension;
        $metaData = parse_ini_file($metaDataFile);
        if (!$metaData) {
            return false;
        }

        $fileSize = filesize($filePath);

        if ($download) {
            header('Content-Disposition: attachment; filename="' . $metaData['original_name'] . '"');
        } else {
            header('Content-Type: ' . $metaData['mime_type']);
        }

        header('Content-Length: ' . $fileSize);

        return readfile($filePath);
    }

    public function searchffile($filename) {
        $iniFiles = glob($this->destinationPath . '*' . $this->metadataExtension);
        foreach ($iniFiles as $iniFile) {
            $metadata = parse_ini_file($iniFile);
            if ($metadata['original_filename'] == $filename) {
                return str_replace($this->metadataExtension, '', $iniFile);
            }
        }
        return false;
    }

    public function searchFiles($name)
    {
        $results = [];
    }
}
