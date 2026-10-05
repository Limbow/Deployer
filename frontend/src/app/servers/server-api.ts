import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';

export interface Server {
  id: number;
  name: string;
  host: string;
  port: number;
  username: string;
  use_ftps: boolean;
  passive: boolean;
  remote_path: string;
  created_at: string;
  updated_at: string;
}

export interface ServerInput {
  name: string;
  host: string;
  port: number;
  username: string;
  password?: string;
  use_ftps: boolean;
  passive: boolean;
  remote_path: string;
}

export interface ConnectionResult {
  message: string;
  protocol: 'FTP' | 'FTPS';
  remote_path: string;
  entries: string[];
}

export interface RemoteFileEntry {
  name: string;
  relative_path: string;
  type: 'directory' | 'file' | 'link' | 'unknown';
  size: number | null;
  modified: string | null;
}

export interface RemoteDirectory {
  server_name: string;
  root_path: string;
  relative_path: string;
  path: string;
  parent_relative_path: string | null;
  entries: RemoteFileEntry[];
}

export interface ApiResponse<T> {
  data: T;
}

@Injectable({ providedIn: 'root' })
export class ServerApi {
  private readonly http = inject(HttpClient);
  private readonly url = '/api/servers';

  list() {
    return this.http.get<ApiResponse<Server[]>>(this.url);
  }

  create(input: ServerInput) {
    return this.http.post<ApiResponse<Server>>(this.url, input);
  }

  update(id: number, input: ServerInput) {
    return this.http.put<ApiResponse<Server>>(`${this.url}/${id}`, input);
  }

  delete(id: number) {
    return this.http.delete<void>(`${this.url}/${id}`);
  }

  test(id: number) {
    return this.http.post<ApiResponse<ConnectionResult>>(`${this.url}/${id}/test`, {});
  }

  browseFiles(id: number, path: string) {
    return this.http.get<ApiResponse<RemoteDirectory>>(`${this.url}/${id}/files`, {
      params: path ? { path } : {},
    });
  }

  downloadFile(id: number, path: string) {
    return this.http.get(`${this.url}/${id}/files/download`, {
      params: { path },
      responseType: 'blob',
    });
  }

  deleteFile(id: number, path: string) {
    return this.http.delete<ApiResponse<{ path: string }>>(`${this.url}/${id}/files`, {
      body: { path },
    });
  }
}
