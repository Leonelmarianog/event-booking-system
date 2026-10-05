# System Design

This folder contains the design of the Event Booking system. The owner and Claude write
it together, one section per PR. All text uses Simple English (ASD-STE100 rules).
All diagrams use Mermaid.

The design spec in `docs/superpowers/specs/` gives the first approved version of the
scope. This folder gives the details.

## Sections

| # | Section | File | Status |
|---|---|---|---|
| 1 | Glossary | [glossary.md](glossary.md) | Done |
| 2 | Business rules | [business-rules.md](business-rules.md) | Done |
| 3 | C4 level 1: system context | [c4-context.md](c4-context.md) | Done |
| 4 | C4 level 2: containers | [c4-containers.md](c4-containers.md) | Done |
| 5 | ERD | [erd.md](erd.md) | In review |
| 6 | Sequence diagrams, one per action | — | Not started |
| 7 | Future features: payments, PDF tickets, file uploads, notifications for event changes | — | Not started |

## Writing rules for this folder

- Use the words in the glossary. Do not use synonyms.
- Give each business rule an ID (for example, `BR-E3`). Other sections refer to rules by ID.
- Use "must" for a requirement and "can" for a permission. Do not use "should" or "may".
