# AGENTS.md

## Tech Stack

- **Frontend:** React + TypeScript, built with Vite
- **Backend:** Laravel
- **Database:** MariaDB

## Project Overview

A public photo gallery web app. Visitors open the site, take a photo with their phone camera, and the photo is uploaded automatically and shown in a shared gallery.

## Core Features

- **Camera capture:** The user can open the device camera (mobile phone camera) directly from the page.
- **Automatic upload:** Once a photo is taken, it is uploaded to the backend automatically, with no extra confirmation step.
- **Public gallery:** The page shows all previously uploaded photos.
- **Minimal UI:** Apart from the camera control, the only visible content is the gallery of uploaded photos.

## Users

- There are no user accounts, registration, or login.
- Everyone is treated as a guest user.

## Frontend Guidelines

- Keep the UI simple but modern.
- Mobile-first: the main use case is taking photos on a phone.

## Backend Priorities

The main focus of this project is the backend:

- **Optimized uploads:** Fast, reliable handling of photo uploads.
- **Image compression:** Compress images with no visible loss of quality (visually lossless), so they load quickly in the gallery.
- **Live preview:** Newly uploaded photos should appear in the gallery on the frontend in near real time.