# Change Log

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning v2.0.0](http://semver.org/).

## [1.1.0] - 2026-05-09
### Added
- Support for inherited (through JOINED) Doctrine ORM entities
- Doctrine ORM metadata caching support

### Fixed
- Bug with identical field names (aliases) of formulas in different Doctrine ORM entities

## [1.0.2] - 2026-05-05
### Fixed
- Bug with the processing of delete/update queries

## [1.0.1] - 2026-05-02
### Fixed
- Bug with duplicate finalization of the SQL

## [1.0.0] - 2026-05-01
### Added
- Ability to create calculated fields in Doctrine ORM entities

[1.0.0]: https://github.com/cryonighter/formula-doctrine/tree/v1.0.0
[1.0.1]: https://github.com/cryonighter/formula-doctrine/tree/v1.0.1
[1.0.2]: https://github.com/cryonighter/formula-doctrine/tree/v1.0.2
[1.1.0]: https://github.com/cryonighter/formula-doctrine/tree/v1.1.0
