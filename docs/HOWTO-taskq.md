# Task Queue

Task Queue is a way to offload heavy or long tasks to the background worker and serialize them.  The application adds tasks to the queue, from where the worker process picks them and executes.

The queue is just a table in the database, so no extra dependencies are required.  The runner is just a PHP script that reads the table over and over.  Task queue operations are subject to regular transactino flow, i.e. not added on rollback.

The whole system is light-weight and simple, suitable for most simple web sites with up to few new tasks per second.


## Adding tasks

Tasks have names and some payload data.  They are added using the `task` named service, e.g.:

```
public function __construct($taskq)
{
    $this->taskq = $taskq;
}

protected function doSomething(): void
{
    $this->taskq->add('files.uploadToCloudTask', [
        'id' => 123,
    ]);
}
```


## Adding task handlers

The built-in implementation treats tasks as service and method name.  For example, a task named "files.uploadToCloudTask" is handled this way:

1. A service named "files" is looked up in the container.
2. Its method named "uploadToCloudTask" is called, with the payload being passed as the first array argument.

Example handler implementation:

```
public function uploadToCloudTask(array $args): void
{
    $id = $args['id'];
    $this->doSomethingWithIt($id);
}
```

The tasks are run with a POST request to an url like `/taskq/{id}/run`, which is handled by the build in controller and routed as described above.  To change this logic, you can:

1. Set the "taskq.exec\_pattern" setting to a proper value, like "/taskq/%u/run.local".
2. Handle the new route the way the built in handler does, see the sources.

It is a good idea to use the "Task" suffix for task methods.


## Running the worker

Go to the project root and run the script `vendor/bin/taskq-runner`.  It doesn't print much, but logs everything.

In production, you normally run it with a crontab like this:

```
* * * * * cd /var/www/project ; php vendor/bin/taskq-runner >/dev/null 2>&1
```

The worker uses file based locking, so this won't create infinite running copies of it -- just one.


## How tasks are handled

Tasks are executed as POST requests, one for each task.  This helps make sure that tasks are executed in the same context that the rest of the application, e.g., no permission conflicts arise.

There is always only one task running at a time, so it doesn't eat much PHP workers and other resources.

You might want to unlimit the execution time for the task handler urls, like `/taskq/{id}/run`.


## Alternatives

Usually people use RabbitMQ or Redis for this kind of job.
