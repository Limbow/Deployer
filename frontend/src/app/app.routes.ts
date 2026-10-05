import { Routes } from '@angular/router';

export const routes: Routes = [
  { path: '', redirectTo: 'servers', pathMatch: 'full' },
  { path: 'servers', loadComponent: () => import('./servers/servers').then((module) => module.Servers) },
  { path: 'projects', loadComponent: () => import('./projects/projects').then((module) => module.Projects) },
  { path: 'settings', loadComponent: () => import('./settings/settings').then((module) => module.Settings) },
  { path: 'builds', loadComponent: () => import('./builds/builds').then((module) => module.Builds) },
  { path: 'history', loadComponent: () => import('./history/history').then((module) => module.History) },
  { path: 'server-files/:serverId', loadComponent: () => import('./server-files/server-files').then((module) => module.ServerFiles) },
  { path: '**', redirectTo: 'servers' },
];
