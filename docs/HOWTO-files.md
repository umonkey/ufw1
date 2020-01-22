# File storage service

File storage is a service which stores file contents on disk and metadata in the database (nodes).  Files are stored as nodes with type=file.


## Usage

Read file node by id:

```
$file = $this->file->get($id);
```

Find file by contents hash:

```
$file = $this->file->getByHash($hash);
```

Get file body.  Reads the file from the file system, including remote one.  Should not be used generally, only to regenerate thumbnails.

```
$file = $this->file->get($id);
$body = $this->file->getBody($file);
```

Save new file, returns the new node.

```
$file = $this->file->add('test.jpg', 'image/jpeg', $body, [
    'kind' => 'photo',
]);
```

Find amount of available disk space:

```
$freeSpace = $this->file->getStorageSize();
```
