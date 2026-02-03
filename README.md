# EasyReservation

EasyReservation is a comprehensive reservation management system built with Laravel (backend) and Vue.js (frontend). It provides a complete solution for managing reservations, events, users, and more with an admin panel and public-facing interfaces.

## Purpose

EasyReservation is designed to help organizations manage reservations for events, resources, or services. The system provides both public-facing reservation capabilities and a comprehensive admin panel for managing all aspects of the reservation process.

## Target Audience

- Event organizers
- Resource managers
- Businesses offering reservation-based services
- Organizations needing a customizable reservation system

## Key Benefits

- Easy-to-use interface for both administrators and users
- Comprehensive admin panel with full control over system settings
- Multi-language support
- Email notifications and broadcasting capabilities
- Audit logging for tracking system activities
- Scalable architecture using Docker containers

## Features

- **Reservation Management**: Create, view, update, and cancel reservations
- **Event Management**: Manage events with dates, locations, and details
- **Admin Panel**: Complete administrative interface for managing all aspects of the system
- **User Management**: Role-based access control with admin and user roles
- **Email System**: Email broadcasting and template management
- **Scheduled Tasks**: Automated task execution with cron scheduling
- **Audit Logging**: Comprehensive audit trail of system activities
- **Multi-language Support**: Internationalization support for multiple languages
- **Two-Factor Authentication**: Enhanced security for admin users

## Admin Panel Access

The admin panel is accessible at `/admin` and requires admin-level authentication. Default admin credentials are:
- Username: admin@example.com
- Password: password

Note: You should change these default credentials after initial setup.

## Architecture

The application follows a modern microservices-like architecture with:

- **Backend**: Laravel 12 with PHP 8.3, serving as the API and business logic layer
- **Frontend**: Vue.js 3 with Vite, providing the user interface
- **Database**: MySQL (with support for SQLite for development)
- **Queue System**: Redis for handling background jobs and scheduled tasks
- **Caching**: Redis for caching operations
- **Scheduler**: Built-in Laravel scheduler for automated tasks

## Technology Stack

- **Backend**: PHP 8.3, Laravel 12, MySQL 8.0
- **Frontend**: Vue.js 3, Vite, JavaScript/ES6+
- **Infrastructure**: Docker, Docker Compose, Nginx
- **Caching**: Redis
- **Task Queue**: Redis with Laravel queue system
- **Authentication**: Laravel Fortify for authentication

## Project Structure

```
EasyReservation/
├── backend/           # Laravel API backend
│   ├── app/           # Application code
│   ├── config/        # Configuration files
│   ├── database/      # Migrations and seeders
│   ├── routes/        # API routes
│   └── ...
├── frontend/          # Vue.js frontend
│   ├── src/           # Source code
│   ├── public/        # Public assets
│   └── ...
└── docker-compose.yml # Docker deployment configuration
```

## Prerequisites

- Docker and Docker Compose
- Git

## Getting Started

### Development Setup

1. Clone the repository:
```bash
git clone <repository-url>
cd EasyReservation
```

2. Start the development environment:
```bash
docker-compose up -d
```

3. Access the application:
   - Frontend: http://localhost
   - Backend API: http://localhost/api
   - Admin Panel: http://localhost/admin

### Development Mode

For local development with hot-reloading:

1. Start the frontend in development mode:
```bash
cd frontend
npm run dev
```

2. Start the backend in development mode:
```bash
cd backend
php artisan serve
```

Note: When running in development mode, you'll need to configure your environment variables appropriately.

### Database Setup

The application uses MySQL for data persistence. The database will be automatically initialized with migrations when you first start the containers.

Initial database credentials:
- Host: mysql
- Port: 3306
- Database: easyreservation
- Username: root
- Password: password

To access the database directly:
```bash
docker-compose exec mysql mysql -u root -p
```

### Environment Variables

The application uses environment variables for configuration. The default `.env` file is already configured for Docker deployment, but you can customize it as needed.

### Environment Configuration

The application uses environment variables for configuration. Copy `.env.example` to `.env` and adjust settings as needed:

```bash
cp backend/.env.example backend/.env
```

## Docker Deployment

A `docker-compose.yaml` file is provided for easy deployment with all required services:

### Services Overview

- **Frontend**: Nginx + Vue.js application (serves the user interface)
- **Backend**: PHP-FPM + Laravel application (API and business logic)
- **Database**: MySQL 8.0 (data persistence)
- **Redis**: For caching and queue management
- **Scheduler**: Laravel scheduler service (handles scheduled tasks)
- **Worker**: Background job processing (processes queued jobs)

To deploy the application:

1. Ensure Docker and Docker Compose are installed
2. Run `docker-compose up -d` to start all services
3. Access the application at http://localhost

The docker-compose file includes:
- Frontend service with Nginx serving Vue.js application
- Backend service with PHP-FPM and Laravel
- MySQL database for data persistence
- Redis instance for caching and queue management
- Scheduler service for Laravel scheduled tasks
- Worker service for background job processing

## API Endpoints

The backend exposes a comprehensive RESTful API for all system functionality:

- `/api/reservations` - Reservation management
- `/api/events` - Event management
- `/api/users` - User management
- `/api/admin` - Admin-specific endpoints
- `/api/settings` - System settings
- `/api/audit-log` - Audit logging
- `/api/email-broadcast` - Email broadcasting
- `/api/scheduled-tasks` - Scheduled task management
- `/api/webhook-templates` - Webhook template management
- `/api/custom-placeholders` - Custom placeholder management

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This project is licensed under the EUPL-1.2 License. A copy of the license is available in the [LICENSE.txt](LICENSE.txt) file.

## Support

For support, please open an issue on the GitHub repository.