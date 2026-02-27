# Contributing to Universal Data Abstraction

Thank you for your interest in contributing to the Universal Data Abstraction project! This document outlines the contribution process and guidelines.

## Code of Conduct

Please read and follow our Code of Conduct to ensure a welcoming and inclusive environment for all contributors.

## How to Contribute

### Reporting Issues

If you find a bug or have a feature request, please open an issue on our GitHub repository. When reporting issues, please include:

- A clear description of the problem
- Steps to reproduce the issue
- Expected behavior
- Actual behavior
- PHP version and database driver information

### Submitting Pull Requests

1. Fork the repository
2. Create a new branch for your feature or bug fix
3. Write clear, concise commit messages
4. Add tests for your changes
5. Ensure all tests pass
6. Submit a pull request

### Code Style

- Follow PSR-12 coding standards
- Use PHP 8.2+ features where appropriate
- Maintain strong typing throughout
- Write comprehensive docblocks
- Keep functions focused and testable

### Testing

All contributions must include appropriate tests. We use PHPUnit for testing:

```bash
composer test
```

## Development Setup

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Run tests:
   ```bash
   composer test
   ```

## License

By contributing to Universal Data Abstraction, you agree that your contributions will be licensed under the MIT License.