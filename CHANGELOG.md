# Change Log

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning v2.0.0](http://semver.org/).

## [1.1.4] - 2026-05-21
### Changed
- Optimization of a SQL query in which a subquery from a formula occurs several times (for example, in the WHERE, GROUP BY, HAVING)

## [1.1.3] - 2026-05-11
### Fixed
- Bug with limit/offset caching

## [1.1.2] - 2026-05-11
### Fixed
- Bug with loading table names

## [1.1.1] - 2026-05-09
### Added
- Support for class metadata factories chaining

### Fixed
- Bug with missing class metadata factory configuration in the configurator

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
[1.1.1]: https://github.com/cryonighter/formula-doctrine/tree/v1.1.1
[1.1.2]: https://github.com/cryonighter/formula-doctrine/tree/v1.1.2
[1.1.3]: https://github.com/cryonighter/formula-doctrine/tree/v1.1.3
[1.1.4]: https://github.com/cryonighter/formula-doctrine/tree/v1.1.4
