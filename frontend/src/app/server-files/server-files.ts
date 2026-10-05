import { Component, computed, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { combineLatest, distinctUntilChanged, finalize, map, Subscription } from 'rxjs';
import { RemoteDirectory, RemoteFileEntry, ServerApi } from '../servers/server-api';
import { errorMessage } from '../shared/api-error';

interface RemoteFolderNode {
  name: string;
  relativePath: string;
  children: RemoteFolderNode[];
  loaded: boolean;
  expanded: boolean;
  loading: boolean;
}

interface VisibleFolderNode {
  node: RemoteFolderNode;
  depth: number;
}

@Component({
  selector: 'app-server-files',
  imports: [RouterLink],
  templateUrl: './server-files.html',
  styleUrls: ['../shared/management.css', './server-files.css'],
})
export class ServerFiles implements OnInit {
  private readonly api = inject(ServerApi);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private request?: Subscription;
  private rootRequest?: Subscription;
  private folderRequest?: Subscription;
  private rootReady = false;

  readonly serverId = signal<number | null>(null);
  readonly currentPath = signal('');
  readonly directory = signal<RemoteDirectory | null>(null);
  readonly searchTerm = signal('');
  readonly visibleEntries = computed(() => {
    const entries = this.directory()?.entries ?? [];
    const query = this.searchTerm().trim().toLowerCase();

    return query === '' ? entries : entries.filter((entry) => entry.name.toLowerCase().includes(query));
  });
  readonly folderTree = signal<RemoteFolderNode | null>(null);
  readonly visibleFolders = computed(() => {
    const root = this.folderTree();
    const rows: VisibleFolderNode[] = [];
    const visit = (node: RemoteFolderNode, depth: number): void => {
      rows.push({ node, depth });
      if (node.expanded) node.children.forEach((child) => visit(child, depth + 1));
    };
    if (root !== null) visit(root, 0);

    return rows;
  });
  readonly loading = signal(false);
  readonly rootLoading = signal(false);
  readonly treeLoadingPath = signal<string | null>(null);
  readonly downloadingPath = signal<string | null>(null);
  readonly deletingPath = signal<string | null>(null);
  readonly error = signal('');
  readonly notice = signal('');

  ngOnInit(): void {
    combineLatest([this.route.paramMap, this.route.queryParamMap])
      .pipe(
        map(([params, query]) => ({
          id: Number(params.get('serverId')),
          path: query.get('path') ?? '',
        })),
        distinctUntilChanged((previous, current) => previous.id === current.id && previous.path === current.path),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ id, path }) => {
        if (!Number.isSafeInteger(id) || id < 1) {
          this.request?.unsubscribe();
          this.rootRequest?.unsubscribe();
          this.folderRequest?.unsubscribe();
          this.serverId.set(null);
          this.rootReady = false;
          this.directory.set(null);
          this.folderTree.set(null);
          this.error.set('El servidor solicitado no es valido.');
          return;
        }
        const serverChanged = this.serverId() !== id;
        if (serverChanged || this.currentPath() !== path) this.searchTerm.set('');
        this.serverId.set(id);
        this.currentPath.set(path);
        this.directory.set(null);
        this.notice.set('');
        if (serverChanged) {
          this.request?.unsubscribe();
          this.folderRequest?.unsubscribe();
          this.treeLoadingPath.set(null);
          this.rootReady = false;
          this.folderTree.set(null);
          this.loadRoot(id);
        } else if (this.rootReady) {
          this.load(id, path);
        }
      });
  }

  busy(): boolean {
    return this.loading() || this.rootLoading() || this.treeLoadingPath() !== null
      || this.downloadingPath() !== null || this.deletingPath() !== null;
  }

  refresh(): void {
    const id = this.serverId();
    if (id === null || this.busy()) return;
    if (this.rootReady) this.load(id, this.currentPath());
    else this.loadRoot(id);
  }

  openDirectory(entry: RemoteFileEntry): void {
    if (entry.type !== 'directory' || this.busy()) return;
    this.navigate(entry.relative_path);
  }

  goUp(): void {
    const parent = this.directory()?.parent_relative_path;
    if (parent !== null && parent !== undefined && !this.busy()) this.navigate(parent);
  }

  toggleFolder(node: RemoteFolderNode): void {
    if (this.busy()) return;
    const current = this.findFolder(node.relativePath);
    if (current === null) return;
    if (current.expanded) {
      this.updateFolderNode(node.relativePath, (folder) => ({ ...folder, expanded: false }));
      return;
    }

    this.updateFolderNode(node.relativePath, (folder) => ({ ...folder, expanded: true }));
    if (!current.loaded) this.loadFolderChildren(node.relativePath);
  }

  openTreeFolder(node: RemoteFolderNode): void {
    if (!this.busy()) this.navigate(node.relativePath);
  }

  hasChildren(node: RemoteFolderNode): boolean {
    return !node.loaded || node.children.length > 0;
  }

  setSearchTerm(event: Event): void {
    const target = event.target;
    if (target instanceof HTMLInputElement) this.searchTerm.set(target.value);
  }

  download(entry: RemoteFileEntry): void {
    const id = this.serverId();
    if (id === null || entry.type !== 'file' || this.busy()) return;

    this.error.set('');
    this.notice.set('');
    this.downloadingPath.set(entry.relative_path);
    this.api.downloadFile(id, entry.relative_path)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.downloadingPath.set(null)))
      .subscribe({
        next: (file) => this.saveDownload(file, entry.name),
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  confirmDelete(entry: RemoteFileEntry): void {
    const id = this.serverId();
    const current = this.directory();
    if (id === null || current === null || entry.type !== 'file' || this.busy()) return;

    const remotePath = this.remotePath(current.root_path, entry.relative_path);
    if (!window.confirm(`¿Borrar permanentemente este archivo remoto?\n\n${remotePath}`)) return;

    this.error.set('');
    this.notice.set('');
    this.deletingPath.set(entry.relative_path);
    this.api.deleteFile(id, entry.relative_path)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.deletingPath.set(null)))
      .subscribe({
        next: () => {
          if (this.serverId() !== id || this.currentPath() !== current.relative_path) return;
          this.notice.set(`Archivo eliminado: ${remotePath}`);
          this.load(id, current.relative_path);
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  formatSize(size: number | null): string {
    if (size === null) return '—';
    if (size < 1024) return `${size} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = size;
    let unit = -1;
    do {
      value /= 1024;
      unit += 1;
    } while (value >= 1024 && unit < units.length - 1);

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
  }

  formatDate(value: string | null): string {
    if (value === null) return '—';
    const ftpDate = value.match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:\.\d+)?$/);
    const date = ftpDate
      ? new Date(Date.UTC(
        Number(ftpDate[1]), Number(ftpDate[2]) - 1, Number(ftpDate[3]),
        Number(ftpDate[4]), Number(ftpDate[5]), Number(ftpDate[6]),
      ))
      : new Date(value);

    return Number.isNaN(date.getTime())
      ? value
      : new Intl.DateTimeFormat('es-AR', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  private load(id: number, path: string): void {
    this.request?.unsubscribe();
    this.error.set('');
    this.loading.set(true);
    this.request = this.api.browseFiles(id, path)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ data }) => {
          this.directory.set(data);
          this.updateFolderTree(data);
        },
        error: (error: unknown) => {
          this.directory.set(null);
          this.error.set(errorMessage(error));
        },
      });
  }

  private loadRoot(id: number): void {
    this.rootRequest?.unsubscribe();
    this.error.set('');
    this.rootLoading.set(true);
    this.rootRequest = this.api.browseFiles(id, '')
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.rootLoading.set(false)))
      .subscribe({
        next: ({ data }) => {
          if (this.serverId() !== id) return;
          this.rootReady = true;
          this.folderTree.set(this.rootFolder(data.entries));
          if (this.currentPath() === '') this.directory.set(data);
          else this.load(id, this.currentPath());
        },
        error: (error: unknown) => {
          if (this.serverId() !== id) return;
          this.folderTree.set(null);
          this.error.set(errorMessage(error));
        },
      });
  }

  private loadFolderChildren(path: string): void {
    const id = this.serverId();
    if (id === null || this.busy()) return;

    this.treeLoadingPath.set(path);
    this.updateFolderNode(path, (node) => ({ ...node, expanded: true, loading: true }));
    this.folderRequest = this.api.browseFiles(id, path)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.treeLoadingPath.set(null);
          this.updateFolderNode(path, (node) => ({ ...node, loading: false }));
        }),
      )
      .subscribe({
        next: ({ data }) => {
          if (this.serverId() !== id) return;
          this.updateFolderNode(path, (node) => ({
            ...node,
            children: this.folderNodes(data.entries, path),
            loaded: true,
            expanded: true,
          }));
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  private rootFolder(entries: RemoteFileEntry[]): RemoteFolderNode {
    return {
      name: '/',
      relativePath: '',
      children: this.folderNodes(entries, ''),
      loaded: true,
      expanded: true,
      loading: false,
    };
  }

  private folderNodes(entries: RemoteFileEntry[], parentPath: string): RemoteFolderNode[] {
    return entries
      .filter((entry) => entry.type === 'directory')
      .map((entry) => ({
        name: entry.name,
        relativePath: parentPath === '' ? entry.name : `${parentPath}/${entry.name}`,
        children: [],
        loaded: false,
        expanded: false,
        loading: false,
      }));
  }

  private updateFolderTree(directory: RemoteDirectory): void {
    this.folderTree.update((root) => {
      if (root === null) return root;
      const segments = directory.relative_path === '' ? [] : directory.relative_path.split('/');
      const branch = this.ensureFolderPath(root, segments, '');

      return this.mapFolderNode(branch, directory.relative_path, (node) => ({
        ...node,
        children: this.folderNodes(directory.entries, directory.relative_path),
        loaded: true,
        expanded: true,
        loading: false,
      }));
    });
  }

  private ensureFolderPath(node: RemoteFolderNode, segments: string[], parentPath: string): RemoteFolderNode {
    if (segments.length === 0) return node;
    const [name, ...rest] = segments;
    if (name === undefined) return node;

    const path = parentPath === '' ? name : `${parentPath}/${name}`;
    const existing = node.children.find((child) => child.relativePath === path);
    const child = existing ?? {
      name,
      relativePath: path,
      children: [],
      loaded: false,
      expanded: false,
      loading: false,
    };
    const updatedChild = this.ensureFolderPath(child, rest, path);
    const children = existing === undefined
      ? [...node.children, updatedChild]
      : node.children.map((current) => current.relativePath === path ? updatedChild : current);

    return { ...node, children, expanded: true };
  }

  private updateFolderNode(path: string, update: (node: RemoteFolderNode) => RemoteFolderNode): void {
    this.folderTree.update((root) => root === null ? root : this.mapFolderNode(root, path, update));
  }

  private mapFolderNode(
    node: RemoteFolderNode,
    path: string,
    update: (node: RemoteFolderNode) => RemoteFolderNode,
  ): RemoteFolderNode {
    if (node.relativePath === path) return update(node);

    return {
      ...node,
      children: node.children.map((child) => this.mapFolderNode(child, path, update)),
    };
  }

  private findFolder(path: string, node: RemoteFolderNode | null = this.folderTree()): RemoteFolderNode | null {
    if (node === null) return null;
    if (node.relativePath === path) return node;
    for (const child of node.children) {
      const match = this.findFolder(path, child);
      if (match !== null) return match;
    }

    return null;
  }

  private navigate(path: string): void {
    this.error.set('');
    this.notice.set('');
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { path: path || null },
      queryParamsHandling: 'merge',
    });
  }

  private remotePath(root: string, relativePath: string): string {
    return root === '/' ? `/${relativePath}` : `${root.replace(/\/+$/, '')}/${relativePath}`;
  }

  private saveDownload(file: Blob, name: string): void {
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
    this.notice.set(`Descarga iniciada: ${name}`);
  }
}
