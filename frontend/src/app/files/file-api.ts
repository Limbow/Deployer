import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { ApiResponse } from '../servers/server-api';

export interface PublishFile {
  path: string;
  size: number;
  hash: string;
  changed: boolean;
  ignored: false;
  remote_path: string;
}

export interface FileNode {
  path: string;
  name: string;
  kind: 'file' | 'directory' | 'link';
  ignored: boolean;
  reason: string | null;
  children?: FileNode[];
  size?: number;
  changed?: boolean;
  remote_path?: string;
}

export interface FileManifest {
  project_id: number;
  server_id: number;
  source_path: string;
  destinations: { backend: string; public: string | null };
  last_deploy_id: number | null;
  generated_at: string;
  hash_algorithm: 'sha1';
  files: PublishFile[];
  obsolete_files: { path: string; remote_path: string; hash: string; size: number }[];
  tree: FileNode[];
  summary: { files: number; changed: number; bytes: number; obsolete: number };
}

export interface SelectableNode extends FileNode {
  children: SelectableNode[];
  selectablePaths: string[];
}

export function selectableTree(nodes: FileNode[]): SelectableNode[] {
  return nodes.map((node) => {
    const children = selectableTree(node.children ?? []);
    const selectablePaths = node.ignored ? [] : node.kind === 'file' ? [node.path] : children.flatMap((child) => child.selectablePaths);
    return { ...node, children, selectablePaths };
  });
}

@Injectable({ providedIn: 'root' })
export class FileApi {
  private readonly http = inject(HttpClient);

  list(projectId: number, serverId: number) {
    return this.http.get<ApiResponse<FileManifest>>(`/api/projects/${projectId}/files`, { params: { server_id: serverId } });
  }
}
