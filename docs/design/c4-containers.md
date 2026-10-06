# C4 Level 2: Containers

This diagram shows the parts inside the Event Booking system. In C4, each part is a
"container": a program that runs on its own, or a data store. The C4 level 1 diagram
shows the system as one box. This diagram opens that box.

Dashed boxes and dashed lines show future parts and connections. Version 1 does not
use them.

## Diagram

```mermaid
flowchart TB
    visitor["<b>Visitor</b><br/>[Person]"]
    user["<b>User</b><br/>[Person]"]
    admin["<b>Admin</b><br/>[Person]"]

    subgraph boundary["Event Booking System"]
        frontend["<b>Web Frontend</b><br/>[Container: React, TypeScript, Inertia]<br/>Shows the pages in the browser."]
        web["<b>Web App</b><br/>[Container: Laravel, Nginx, PHP-FPM, Node]<br/>Handles HTTP requests.<br/>Runs the Actions.<br/>Renders the first page load."]
        worker["<b>Worker</b><br/>[Container: Laravel queue worker]<br/>Runs queued jobs.<br/>Sends notifications."]
        scheduler["<b>Scheduler</b><br/>[Container: Laravel scheduler]<br/>Starts the daily reminders."]
        migrate["<b>Migrate</b><br/>[Container: one-off task]<br/>Changes the database schema<br/>before each deploy."]
        db[("<b>Database</b><br/>[Container: PostgreSQL]<br/>Users, events, bookings,<br/>failed jobs.")]
        redis[("<b>Redis</b><br/>[Container: Redis]<br/>Queue, cache, sessions,<br/>rate limits.")]
    end

    email["<b>Email Service</b><br/>[External System]<br/>SMTP server."]
    payment["<b>Payment Provider</b><br/>[External System, future]"]
    storage["<b>Object Storage</b><br/>[External System, future]"]

    visitor -->|"Uses<br/>[HTTPS]"| frontend
    user -->|"Uses<br/>[HTTPS]"| frontend
    admin -->|"Uses<br/>[HTTPS]"| frontend

    frontend -->|"Sends Inertia requests<br/>[HTTPS, JSON]"| web
    web -->|"Reads and writes<br/>[SQL]"| db
    web -->|"Sessions, cache, rate limits,<br/>queues jobs [RESP]"| redis

    worker -->|"Takes queued jobs<br/>[RESP]"| redis
    worker -->|"Reads data<br/>[SQL]"| db
    worker -->|"Sends emails<br/>[SMTP]"| email

    scheduler -->|"Reads events,<br/>marks reminders sent [SQL]"| db
    scheduler -->|"Queues reminder jobs<br/>[RESP]"| redis

    migrate -->|"Changes the schema<br/>[SQL]"| db

    web -.->|"Creates payments and refunds<br/>[HTTPS]"| payment
    payment -.->|"Sends payment and refund results<br/>[HTTPS webhook]"| web
    web -.->|"Writes and reads uploads<br/>[HTTPS, S3 API]"| storage
    worker -.->|"Writes PDF tickets<br/>[HTTPS, S3 API]"| storage

    classDef person fill:#08427b,stroke:#052e56,color:#ffffff
    classDef container fill:#438dd5,stroke:#2e6295,color:#ffffff
    classDef external fill:#6b6b6b,stroke:#4d4d4d,color:#ffffff
    classDef future fill:#9a9a9a,stroke:#4d4d4d,color:#ffffff,stroke-dasharray:6 4

    class visitor,user,admin person
    class frontend,web,worker,scheduler,migrate,db,redis container
    class email external
    class payment,storage future
    style boundary fill:none,stroke:#888888,stroke-dasharray:4 4
```

## Containers

| Container    | Technology                            | Responsibility                                                                                                                                                                                         |
| ------------ | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Web Frontend | React, TypeScript, Inertia, shadcn/ui | Shows the pages in the browser. Sends the forms to the Web App. The Web App serves its files.                                                                                                          |
| Web App      | Laravel, Nginx, PHP-FPM, Node         | Handles all HTTP requests. Does authentication, authorization, validation and rate limits. Runs the Actions. Puts notifications on the queue. Renders the first page load on the server (Inertia SSR). |
| Worker       | Laravel queue worker                  | Takes jobs from the queue and runs them. In v1, all jobs send notifications. If a job fails three times, the Worker writes it to the `failed_jobs` table.                                              |
| Scheduler    | Laravel scheduler                     | Each day, finds the events that start in the next 24 hours. Puts a reminder job on the queue for each attendee (BR-N4, BR-N5).                                                                         |
| Migrate      | Laravel migrations                    | Runs one time before each deploy. Changes the database schema. Stops when the migrations are complete.                                                                                                 |
| Database     | PostgreSQL                            | Keeps all business data. It is the only source of truth. Its constraints enforce some rules (BR-E8, BR-B4, BR-B5).                                                                                     |
| Redis        | Redis                                 | Keeps the queue, the cache, the sessions and the rate-limit counters. The data in Redis is temporary.                                                                                                  |

## One image, four roles

The Web App, Worker, Scheduler and Migrate use the same Docker image. The first argument
of the container selects the role:

| Role        | Command                                                       |
| ----------- | ------------------------------------------------------------- |
| `web`       | supervisord: Nginx, PHP-FPM and the Inertia SSR server (Node) |
| `worker`    | `php artisan queue:work redis --tries=3 --max-time=3600`      |
| `scheduler` | `php artisan schedule:work`                                   |
| `migrate`   | `php artisan migrate --force`                                 |

Each role runs `php artisan optimize` first.

The Web Frontend is not a separate image. The build compiles it into two bundles. The
browser bundle is static files that the Web App serves. The Web App uses the SSR bundle
to render the first page load on the server. After that, the browser renders the pages.

## Future connections

| Connection                 | Feature      | Description                                                                                   |
| -------------------------- | ------------ | --------------------------------------------------------------------------------------------- |
| Web App → Payment Provider | Payments     | The Web App creates a payment when a user books a paid ticket. It also requests refunds.      |
| Payment Provider → Web App | Payments     | The provider sends the payment and refund results to a webhook endpoint on the Web App.       |
| Scheduler → Database       | Payments     | Each minute, the Scheduler cancels the pending bookings that passed their 30 minutes (BR-P8). |
| Web App → Object Storage   | File uploads | The Web App writes the files that users upload, and reads them back.                          |
| Worker → Object Storage    | PDF tickets  | The Worker makes the PDF tickets in a queued job and writes them to the storage.              |

## Not in this diagram

- **Vite dev server and Mailpit.** These run only in local development. Mailpit takes the place of the Email Service there.
