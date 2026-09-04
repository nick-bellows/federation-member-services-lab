---
title: Federation Member Services Lab
---

# Federation Member Services Lab

An engineering modernization lab: an existing open-source club-management platform ([vereinfacht](https://github.com/vereinfacht/vereinfacht), MIT), extended step by step into a member-services system for a fictional national soccer federation, without a rewrite. Validated in CI on three database engines; rehearsed from release images; not deployed.

- **[The case study](CASE_STUDY.md)**: the argument from the inherited system through eleven milestones, every number linked to a retained run, and what was not done.
- **[The demo](assets/demo.webm)**: 29 seconds, recorded from a browser spec against the release images (applicant starts, attaches, submits; reviewer takes it under review, accepts, approves, refreshes credentials; the applicant sees the decision and the participation status).
- **[The repository](https://github.com/nick-bellows/federation-member-services-lab)**, with the README's milestone table and the CI workflow.

## Reading order for five minutes

1. [The threat model](THREAT_MODEL.md), for how "mitigated", "partly" and "upstream" are used instead of "done".
2. One decision record, [ADR-0010](adr/0010-transactional-outbox-and-consumers.md), for how decisions are recorded with their alternatives.
3. One incident rehearsal, [INCIDENT-003](incidents/INCIDENT-003-worker-fails-after-approval.md), for how failure is practised.
4. [The deployment design](DEPLOYMENT.md) and [the release checklist](RELEASE.md), labelled planned where they are.
5. [The learning log](LEARNING_LOG.md), for what went wrong in each milestone and what it taught.

The federation, its organizations and every person in the seed data are invented. This project is not affiliated with, endorsed by, or based on the internal systems of any real federation.
