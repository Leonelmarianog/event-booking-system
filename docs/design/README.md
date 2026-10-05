# System Design

This folder contains the design of the Event Booking system. All text uses Simple
English (ASD-STE100 rules). All diagrams use Mermaid.

The design spec in `docs/superpowers/specs/` gives the first approved version of the
scope. This folder gives the details.

## Sections

| #   | Section                                                                                              | File                                     | Status                                                                                            |
| --- | ---------------------------------------------------------------------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------- |
| 1   | Glossary                                                                                             | [glossary.md](glossary.md)               | Done                                                                                              |
| 2   | Business rules                                                                                       | [business-rules.md](business-rules.md)   | Done                                                                                              |
| 3   | C4 level 1: system context                                                                           | [c4-context.md](c4-context.md)           | Done                                                                                              |
| 4   | C4 level 2: containers                                                                               | [c4-containers.md](c4-containers.md)     | Done                                                                                              |
| 5   | ERD                                                                                                  | [erd.md](erd.md)                         | In review                                                                                         |
| 6   | Sequence diagrams                                                                                    | In each Action directory                 | See below                                                                                         |
| 7   | Future features: payments, PDF tickets, file uploads, notifications for event changes, announcements | [future-features.md](future-features.md) | Payments, refunds, PDF tickets, cover image done. Event change emails and announcements in review |

## Sequence diagrams

The sequence diagrams are not in this folder. Each Action has a directory under
`app/Actions/`, and that directory holds a `README.md` with the sequence diagrams of the
Action. The README is written in the same PR as the Action. Diagrams for future features
are written only when the feature is implemented.

Each README follows these rules:

- One diagram for each outcome: the success path and each failure. No `alt` blocks.
- The participants are the real classes: Browser, Middleware, Controller, FormRequest,
  Policy, Action, Model, PostgreSQL, Redis, Worker, Email Service.
- The diagrams show the transactions, the row locks and the notifications that the
  system queues after the commit.
- Middleware (authentication, CSRF, rate limits) is one participant with one step.
- Each failure diagram gives the IDs of the rules that cause it.

## Writing rules for this folder

- Use the words in the glossary. Do not use synonyms.
- Give each business rule an ID (for example, `BR-E3`). Other sections refer to rules by ID.
- Use "must" for a requirement and "can" for a permission. Do not use "should" or "may".
