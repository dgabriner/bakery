# GTD Project Tracker

A simple project tracking application based on the Getting Things Done (GTD) methodology.

## Features

- Add new projects
- Track project status
- Mark projects as complete
- Delete projects
- Persistent storage of projects
- Modern, clean interface

## Installation

1. Ensure Python 3.x is installed on your system
2. Install the required dependencies:
   ```
   pip install -r requirements.txt
   ```
3. Run the application:
   ```
   python project_tracker.py
   ```

## Usage

1. Enter a project name in the text field
2. Click "Add Project" to create a new project
3. Right-click on any project to:
   - Mark it as complete
   - Delete the project

Projects are automatically saved to `projects.json` and will persist between sessions.
