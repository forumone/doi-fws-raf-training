# FWS Reusable Application

This is the FWS reusable application for FWS projects. It provides a base configuration and setup for Drupal 10 projects, including essential modules, configurations, and recipes.

## Table of Contents

- [Introduction](#introduction)
- [Prerequisites](#prerequisites)
- [Getting Started](#getting-started)
  - [Using DDEV](#using-ddev)
  - [Using Docksal](#using-docksal)
- [Theming](#theming)
- [Project Structure](#project-structure)
- [Contributing](#contributing)
- [License](#license)

## Introduction

The FWS reusable application is designed to streamline the development of Drupal 10 projects by providing a standardized setup with commonly used modules and configurations. It leverages tools like DDEV and Docksal for local development, making it easy to get started quickly.

## Prerequisites

- **Git**: For cloning the repository.
- **Docker**: Required by both DDEV and Docksal.
- **DDEV** or **Docksal**: Local development environments.

## Getting Started

You can set up the project locally using either DDEV or Docksal. Follow the instructions below based on your preferred local development environment.

### Using DDEV

[DDEV](https://ddev.readthedocs.io/en/stable/) is an open-source tool that simplifies setting up a local PHP development environment.

1. **Install DDEV**: Follow the [official installation guide](https://ddev.readthedocs.io/en/stable/#installation).

2. **Clone the Repository**:

   ```bash
   git clone [repository-url]
   cd [repository-directory]
   ```

3. **Start DDEV**:

   ```bash
   ddev start
   ```

4. **Install FWS**:

   ```bash
   ddev install
   ```

   This command runs the installation script that:

   - Installs Composer dependencies
   - Installs Drupal with the minimal profile
   - Applies FWS core recipe
   - Runs cleanup scripts
   - Generates a login link

### Using Docksal

[Docksal](https://docksal.io/) is a Docker-based development environment for web projects.

1. **Install Docksal**: Follow the [official installation guide](https://docs.docksal.io/getting-started/setup/).

2. **Clone the Repository**:

   ```bash
   git clone [repository-url]
   cd [repository-directory]
   ```

3. **Start Docksal**:

   ```bash
   fin start
   ```

4. **Install FWS**:

   ```bash
   fin install
   ```

   This command runs the installation script that performs the same steps as the DDEV installation.

## Theming

The application uses the `fws_raf` theme, which is a subtheme of the Bootstrap-based `fws_gov` theme. The parent theme is automatically installed via Composer dependencies during the installation process.

## Project Structure

```
project-root/
├── .ddev/                  # DDEV configuration
├── .docksal/               # Docksal configuration
├── recipes/                # FWS installation recipes
├── scripts/               # Installation and utility scripts
└── README.md
```
