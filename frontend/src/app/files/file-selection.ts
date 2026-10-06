import { Component, computed, DestroyRef, effect, inject, input, output, signal, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { exhaustMap, finalize, Subscription, timer } from 'rxjs';
import { Deploy, DeployApi, DeployInput, DeployStatus } from '../deploys/deploy-api';
import { errorMessage } from '../shared/api-error';
import { DeployRemoteDirectory, DeployRemoteEntry, FileApi, FileManifest, FileNode, selectableTree } from './file-api';
import { FileTree } from './file-tree';

@Component({
  selector: 'app-file-selection',
  imports: [FormsModule, FileTree],
  templateUrl: './file-selection.html',
  styleUrls: ['../shared/management.css', './file-selection.css'],
})
export class FileSelection {
  readonly projectId = input.required<number>();
  readonly serverId = input.required<number>();
  readonly buildId = input<number | null>(null);
  readonly currentVersion = input('');
  readonly serverName = input('');
  readonly enabled = input(false);
  readonly revision = input(0);
  private readonly api = inject(FileApi);
  private readonly deployApi = inject(DeployApi);
  private readonly destroyRef = inject(DestroyRef);
  private request?: Subscription;
  private activeDeployRequest?: Subscription;
  private polling?: Subscription;
  private remoteRequest?: Subscription;
  private parentActivity = false;
  readonly deployActivity = output<boolean>();
  readonly loading = signal(false);
  readonly error = signal('');
  readonly manifest = signal<FileManifest | null>(null);
  readonly selected = signal<ReadonlySet<string>>(new Set());
  readonly showIgnored = signal(false);
  readonly selectionExpanded = signal(false);
  readonly deploy = signal<Deploy | null>(null);
  readonly deployLoading = signal(false);
  readonly deploySubmitting = signal(false);
  readonly deployError = signal('');
  readonly deployPollFailed = signal(false);
  readonly version = signal('');
  readonly changes = signal('');
  readonly deleteObsolete = signal(false);
  readonly remoteDirectory = signal<DeployRemoteDirectory | null>(null);
  readonly remoteLoading = signal(false);
  readonly remoteError = signal('');
  readonly deleteFiles = signal<ReadonlyMap<string, string>>(new Map());
  readonly deletionSelection = computed(() => [...this.deleteFiles()].map(([path, remote]) => ({ path, remote })));
  readonly confirmDeploy = signal(false);
  readonly tree = computed(() => {
    const prune = (nodes: FileNode[]): FileNode[] => nodes.filter((node) => this.showIgnored() || !node.ignored)
      .map((node) => ({ ...node, children: prune(node.children ?? []) }));
    return selectableTree(prune(this.manifest()?.tree ?? []));
  });
  readonly selectedFiles = computed(() => (this.manifest()?.files ?? []).filter((file) => this.selected().has(file.path)));
  readonly selectedBytes = computed(() => this.selectedFiles().reduce((bytes, file) => bytes + file.size, 0));
  readonly allChangedSelected = computed(() => (this.manifest()?.files ?? [])
    .filter((file) => file.changed).every((file) => this.selected().has(file.path)));
  readonly canCleanObsolete = computed(() => (this.manifest()?.obsolete_files.length ?? 0) > 0 && this.allChangedSelected());
  readonly deployActive = computed(() => this.isActive(this.deploy()?.status));
  readonly canDeploy = computed(() => {
    const current = this.manifest();
    const hasFiles = this.selectedFiles().length > 0;
    const cleanOnly = (this.deleteObsolete() && (current?.obsolete_files.length ?? 0) > 0) || this.deleteFiles().size > 0;
    return !!current && this.enabled() && this.serverId() > 0 && !this.loading() && !this.deployLoading()
      && !this.deploySubmitting() && !this.remoteLoading() && !this.deployActive() && this.deploy()?.status !== 'success'
      && this.isVersionValid() && (hasFiles || cleanOnly)
      && (!this.deleteObsolete() || this.canCleanObsolete())
      && this.confirmDeploy();
  });
  readonly ignoredCount = computed(() => {
    const count = (nodes: FileNode[]): number => nodes.reduce((total, node) => total + (node.ignored ? 1 : count(node.children ?? [])), 0);
    return count(this.manifest()?.tree ?? []);
  });

  constructor() {
    effect(() => {
      this.projectId();
      this.serverId();
      this.enabled();
      this.revision();
      untracked(() => this.reset());
    });
  }

  private reset(): void {
    this.request?.unsubscribe();
    this.activeDeployRequest?.unsubscribe();
    this.polling?.unsubscribe();
    this.remoteRequest?.unsubscribe();
    this.remoteDirectory.set(null);
    this.remoteLoading.set(false);
    this.remoteError.set('');
    this.deleteFiles.set(new Map());
    this.manifest.set(null);
    this.selected.set(new Set());
    this.error.set('');
    this.showIgnored.set(false);
    this.selectionExpanded.set(false);
    this.deploy.set(null);
    this.deployLoading.set(false);
    this.deploySubmitting.set(false);
    this.deployError.set('');
    this.deployPollFailed.set(false);
    this.version.set('');
    this.changes.set('');
    this.deleteObsolete.set(false);
    this.confirmDeploy.set(false);
    this.setParentActivity(false);
  }

  load(): void {
    if (!this.enabled() || !this.serverId() || this.loading() || this.deployActive() || this.deploySubmitting()) return;
    this.reset();
    this.loading.set(true);
    this.request = this.api.list(this.projectId(), this.serverId())
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ data }) => {
          this.manifest.set(data);
          this.selected.set(new Set(data.files.filter((file) => file.changed).map((file) => file.path)));
          this.restoreActiveDeploy();
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  choose(mode: 'all' | 'changed' | 'none'): void {
    if (!this.enabled() || this.loading() || this.deployActive() || this.deploySubmitting()) return;
    const files = this.manifest()?.files ?? [];
    this.selected.set(new Set(files.filter((file) => mode === 'all' || (mode === 'changed' && file.changed)).map((file) => file.path)));
    this.confirmDeploy.set(false);
  }

  toggle(event: { paths: string[]; checked: boolean }): void {
    if (!this.enabled() || this.loading() || this.deployActive() || this.deploySubmitting()) return;
    const allowed = new Set((this.manifest()?.files ?? []).map((file) => file.path));
    const selected = new Set(this.selected());
    for (const path of event.paths) {
      if (!allowed.has(path)) continue;
      if (event.checked) selected.add(path);
      else selected.delete(path);
    }
    this.selected.set(selected);
    this.confirmDeploy.set(false);
  }

  setVersion(value: string): void {
    this.version.set(value);
    this.confirmDeploy.set(false);
  }

  setChanges(value: string): void {
    this.changes.set(value);
    this.confirmDeploy.set(false);
  }

  setDeleteObsolete(value: boolean): void {
    if (value) {
      const selected = new Set(this.selected());
      for (const file of this.manifest()?.files ?? []) {
        if (file.changed) selected.add(file.path);
      }
      this.selected.set(selected);
      this.deleteFiles.set(new Map());
    }
    this.deleteObsolete.set(value);
    this.confirmDeploy.set(false);
  }

  loadRemote(destination: 'backend' | 'public' = 'backend', path = ''): void {
    if (!this.manifest() || !this.enabled() || this.remoteLoading() || this.deployActive() || this.deploySubmitting()) return;
    this.remoteLoading.set(true);
    this.remoteError.set('');
    this.remoteRequest = this.api.remote(this.projectId(), this.serverId(), destination, path)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.remoteLoading.set(false)))
      .subscribe({
        next: ({ data }) => this.remoteDirectory.set(data),
        error: (error: unknown) => this.remoteError.set(errorMessage(error)),
      });
  }

  toggleRemote(entry: DeployRemoteEntry, checked: boolean): void {
    if (!this.enabled() || this.remoteLoading() || this.deployActive() || this.deploySubmitting()
      || entry.type !== 'file' || entry.deletion_reason !== null) return;
    const current = this.remoteDirectory();
    if (!current || !current.entries.includes(entry)) return;
    const selected = new Map(this.deleteFiles());
    if (checked) selected.set(entry.publish_path, `${current.remote_path.replace(/\/$/, '')}/${entry.name}`);
    else selected.delete(entry.publish_path);
    this.deleteFiles.set(selected);
    this.deleteObsolete.set(false);
    this.confirmDeploy.set(false);
  }

  removeDeletion(path: string): void {
    if (this.deployActive() || this.deploySubmitting()) return;
    const selected = new Map(this.deleteFiles());
    selected.delete(path);
    this.deleteFiles.set(selected);
    this.confirmDeploy.set(false);
  }

  startDeploy(): void {
    if (!this.canDeploy()) return;
    const request: DeployInput = {
      project_id: this.projectId(),
      server_id: this.serverId(),
      build_id: this.buildId(),
      version: this.version(),
      changes: this.changes(),
      delete_obsolete: this.deleteObsolete(),
      files: this.selectedFiles().map((file) => file.path),
      ...(this.deleteFiles().size > 0 ? { delete_files: [...this.deleteFiles().keys()] } : {}),
    };
    this.deployError.set('');
    this.deployPollFailed.set(false);
    this.confirmDeploy.set(false);
    this.setDeploy(null);
    this.deploySubmitting.set(true);
    this.deployApi.start(request)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.deploySubmitting.set(false)))
      .subscribe({
        next: ({ data }) => {
          this.setDeploy(data);
          this.watch();
        },
        error: (error: unknown) => {
          this.deployError.set(errorMessage(error));
          this.recoverActiveDeploy();
        },
      });
  }

  watch(): void {
    const current = this.deploy();
    if (!current || !this.isActive(current.status)) return;
    this.polling?.unsubscribe();
    this.deployError.set('');
    this.deployPollFailed.set(false);
    this.polling = timer(0, 1500).pipe(
      exhaustMap(() => this.deployApi.get(current.id)),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: ({ data }) => {
        this.setDeploy(data);
        if (!this.isActive(data.status)) {
          this.polling?.unsubscribe();
          this.polling = undefined;
        }
      },
      error: (error: unknown) => {
        this.deployPollFailed.set(true);
        this.deployError.set(`El seguimiento se interrumpio; el deploy puede seguir ejecutandose. ${errorMessage(error)}`);
        this.polling = undefined;
      },
    });
  }

  statusLabel(status: DeployStatus): string {
    return {
      queued: 'En cola',
      running: 'En ejecucion',
      success: 'Completado',
      failed: 'Fallido',
      rolled_back: 'Restaurado',
    }[status];
  }

  fileStatusLabel(status: Deploy['files'][number]['status']): string {
    return {
      queued: 'En cola',
      uploading: 'Subiendo',
      uploaded: 'Subido',
      pending_delete: 'Pendiente de limpieza',
      deleting: 'Respaldando / eliminando',
      deleted: 'Eliminado y respaldado',
      absent: 'Ya ausente',
      skipped: 'Conservado',
      failed: 'Fallido',
    }[status];
  }

  isVersionValid(): boolean {
    return /^[A-Za-z0-9][A-Za-z0-9.+_-]{0,254}$/.test(this.version());
  }

  private isActive(status: DeployStatus | undefined): boolean {
    return status === 'queued' || status === 'running';
  }

  private setDeploy(deploy: Deploy | null): void {
    this.deploy.set(deploy);
    this.setParentActivity(this.isActive(deploy?.status));
  }

  private setParentActivity(active: boolean): void {
    if (active === this.parentActivity) return;
    this.parentActivity = active;
    this.deployActivity.emit(active);
  }

  private restoreActiveDeploy(): void {
    this.activeDeployRequest?.unsubscribe();
    this.deployLoading.set(true);
    this.activeDeployRequest = this.deployApi.active(this.projectId(), this.serverId())
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.deployLoading.set(false)))
      .subscribe({
        next: ({ data }) => {
          if (!data) return;
          this.setDeploy(data);
          this.watch();
        },
        error: (error: unknown) => this.deployError.set(`No se pudo recuperar el deploy activo. ${errorMessage(error)}`),
      });
  }

  private recoverActiveDeploy(): void {
    this.activeDeployRequest?.unsubscribe();
    this.deployLoading.set(true);
    this.activeDeployRequest = this.deployApi.active(this.projectId(), this.serverId())
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.deployLoading.set(false)))
      .subscribe({
        next: ({ data }) => {
          if (!data) return;
          this.setDeploy(data);
          this.watch();
        },
        error: (error: unknown) => this.deployError.set(`${this.deployError()} No se pudo recuperar el deploy activo. ${errorMessage(error)}`),
      });
  }

  formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  setReviewOpen(event: Event): void {
    if (event.target instanceof HTMLDetailsElement) {
      this.selectionExpanded.set(event.target.open);
    }
  }
}
