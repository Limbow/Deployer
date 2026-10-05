import { afterNextRender, Component, computed, ElementRef, inject, Injector, OnInit, signal, viewChild } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize, forkJoin, of } from 'rxjs';
import { Server, ServerApi } from '../servers/server-api';
import { SettingsApi } from '../settings/settings-api';
import { errorMessage } from '../shared/api-error';
import { Project, ProjectApi, ProjectCandidate, ProjectInput, ScanResult } from './project-api';

@Component({
  selector: 'app-projects',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './projects.html',
  styleUrls: ['../shared/management.css', './projects.css'],
})
export class Projects implements OnInit {
  private readonly api = inject(ProjectApi);
  private readonly serverApi = inject(ServerApi);
  private readonly settingsApi = inject(SettingsApi);
  private readonly fb = inject(FormBuilder);
  private readonly injector = inject(Injector);
  private readonly nameInput = viewChild<ElementRef<HTMLInputElement>>('projectName');
  readonly projects = signal<Project[]>([]);
  readonly servers = signal<Server[]>([]);
  readonly root = signal('');
  readonly loading = signal(false);
  readonly scanning = signal(false);
  readonly saving = signal(false);
  readonly deleting = signal(false);
  readonly ready = signal(false);
  readonly includeHidden = signal(false);
  readonly changingVisibility = signal(false);
  readonly scanResult = signal<ScanResult | null>(null);
  readonly typeFilter = signal<'all' | Project['type']>('all');
  readonly filteredProjects = computed(() => this.projects().filter((project) => this.matchesType(project.type)));
  readonly filteredCandidates = computed(() => (this.scanResult()?.projects ?? []).filter((candidate) => this.matchesType(candidate.type)));
  readonly editingId = signal<number | null>(null);
  readonly formOpen = signal(false);
  readonly deleteCandidate = signal<Project | null>(null);
  readonly versionBumpCandidate = signal<Project | null>(null);
  readonly versionBumpType = signal<'patch' | 'minor' | 'major'>('patch');
  readonly bumpingVersion = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly form = this.fb.nonNullable.group({
    type: this.fb.nonNullable.control<'angular' | 'laravel'>('angular'),
    name: ['', [Validators.required, Validators.maxLength(255)]],
    local_path: ['', [Validators.required, Validators.maxLength(768)]],
    angular_project: ['', [Validators.maxLength(255)]],
    build_output_path: ['', [Validators.required, Validators.maxLength(2048)]],
    build_command: ['', [Validators.maxLength(4096)]],
    ignore_patterns_text: ['*.map', [Validators.maxLength(25600)]],
    current_version: ['', [Validators.required, Validators.maxLength(255)]],
    servers: this.fb.array<ReturnType<Projects['connectionForm']>>([]),
  });

  ngOnInit(): void {
    this.load();
  }

  busy(): boolean {
    return this.loading() || this.scanning() || this.saving() || this.deleting() || this.changingVisibility() || this.bumpingVersion();
  }

  load(rescan = false): void {
    if (this.busy()) return;
    this.error.set('');
    this.loading.set(true);
    forkJoin({
      projects: this.api.list(this.includeHidden()), servers: this.serverApi.list(),
      settings: this.settingsApi.get(), scan: rescan ? this.api.scan(this.includeHidden()) : of(null),
    })
      .pipe(finalize(() => this.loading.set(false))).subscribe({
        next: (response) => {
          this.projects.set(response.projects.data);
          this.servers.set(response.servers.data);
          if (this.root() !== response.settings.data.projects_root) this.scanResult.set(null);
          this.root.set(response.settings.data.projects_root);
          if (response.scan) this.scanResult.set(response.scan.data);
          this.ready.set(true);
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  scan(): void {
    if (this.busy() || !this.ready()) return;
    this.clearFeedback();
    this.scanning.set(true);
    this.api.scan(this.includeHidden()).pipe(finalize(() => this.scanning.set(false))).subscribe({
      next: ({ data }) => {
        this.scanResult.set(data);
        this.notice.set(`Escaneo terminado: ${data.projects.length} proyectos detectados, ${data.issues.length} problemas.`);
      },
      error: (error: unknown) => {
        this.scanResult.set(null);
        this.error.set(errorMessage(error));
      },
    });
  }

  configure(candidate: ProjectCandidate): void {
    if (this.busy() || candidate.registered_id !== null) return;
    this.clearFeedback();
    this.versionBumpCandidate.set(null);
    this.deleteCandidate.set(null);
    this.editingId.set(null);
    this.form.controls.servers.clear();
    this.form.reset({
      ...candidate, angular_project: candidate.angular_project ?? '', build_command: candidate.build_command ?? '',
      ignore_patterns_text: candidate.type === 'laravel'
        ? '.env\n.env.*\n.git\n.git/*\nnode_modules\nnode_modules/*\nstorage\nstorage/*\nbootstrap/cache/*.php\npublic/storage\npublic/storage/*\ntests\ntests/*\n*.log' : '*.map',
    });
    this.formOpen.set(true);
    this.focusEditor();
  }

  edit(project: Project): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.versionBumpCandidate.set(null);
    this.deleteCandidate.set(null);
    this.editingId.set(project.id);
    this.form.controls.servers.clear();
    const { servers, ...values } = project;
    this.form.reset({
      ...values, angular_project: project.angular_project ?? '', build_command: project.build_command ?? '',
      ignore_patterns_text: project.ignore_patterns.join('\n'),
    });
    for (const server of servers) {
      this.form.controls.servers.push(this.connectionForm(server.id, server.pivot.label, server.pivot.remote_path_override ?? '', server.pivot.public_remote_path ?? ''));
    }
    this.formOpen.set(true);
    this.focusEditor();
  }

  cancel(): void {
    if (this.busy()) return;
    this.formOpen.set(false);
    this.editingId.set(null);
    this.form.reset();
    this.form.controls.servers.clear();
    this.clearFeedback();
  }

  addServer(): void {
    if (this.busy()) return;
    const folder = this.form.controls.local_path.value.split(/[\\/]/).filter(Boolean).at(-1) ?? '';
    this.form.controls.servers.push(this.connectionForm(0, 'production', this.form.controls.type.value === 'laravel' ? `/${folder}` : ''));
  }

  removeServer(index: number): void {
    if (!this.busy()) this.form.controls.servers.removeAt(index);
  }

  connectionForm(serverId = 0, label = 'production', path = '', publicPath = '') {
    return this.fb.nonNullable.group({
      server_id: [serverId, [Validators.required, Validators.min(1)]],
      label: [label, [Validators.required, Validators.maxLength(255)]],
      remote_path_override: [path, [Validators.maxLength(1024), Validators.pattern(/^\/[^\x00-\x1f\x7f\\]*$/)]],
      public_remote_path: [publicPath, [Validators.maxLength(1024), Validators.pattern(/^\/[^\x00-\x1f\x7f\\]*$/)]],
    });
  }

  save(): void {
    if (this.busy() || !this.formOpen()) return;
    this.form.markAllAsTouched();
    const raw = this.form.getRawValue();
    if (this.form.invalid || (raw.type === 'angular' && (!raw.angular_project || !raw.build_command))) {
      this.error.set('Revisa los datos del proyecto y las conexiones asociadas.');
      return;
    }
    const { ignore_patterns_text, servers, ...values } = this.form.getRawValue();
    const input: ProjectInput = {
      ...values,
      angular_project: values.type === 'laravel' ? null : values.angular_project,
      build_command: values.build_command || null,
      ignore_patterns: ignore_patterns_text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean),
      servers: servers.map((server) => ({
        ...server, remote_path_override: server.remote_path_override || null,
        public_remote_path: values.type === 'laravel' ? server.public_remote_path || null : null,
      })),
    };
    this.clearFeedback();
    this.saving.set(true);
    const id = this.editingId();
    const request = id === null ? this.api.create(input) : this.api.update(id, input);
    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: ({ data }) => {
        const previous = this.projects().find((project) => project.id === id);
        if (previous && previous.local_path !== data.local_path) {
          this.updateRegistration(previous.local_path, null, null);
        }
        this.projects.update((projects) => [...projects.filter((item) => item.id !== data.id), data].sort((a, b) => a.name.localeCompare(b.name)));
        this.updateRegistration(data.local_path, data.id, data.angular_project);
        this.formOpen.set(false);
        this.editingId.set(null);
        this.notice.set(id === null ? 'Proyecto agregado a mis proyectos.' : 'Proyecto actualizado.');
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  requestDelete(project: Project): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.versionBumpCandidate.set(null);
    this.deleteCandidate.set(project);
  }

  remove(): void {
    const project = this.deleteCandidate();
    if (project === null || this.busy()) return;
    this.clearFeedback();
    this.deleting.set(true);
    this.api.delete(project.id).pipe(finalize(() => this.deleting.set(false))).subscribe({
      next: () => {
        this.projects.update((projects) => projects.filter((item) => item.id !== project.id));
        this.updateRegistration(project.local_path, null, null);
        this.deleteCandidate.set(null);
        if (this.editingId() === project.id) {
          this.formOpen.set(false);
          this.editingId.set(null);
        }
        this.notice.set('Registro eliminado. Los archivos locales no se modificaron.');
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  requestVersionBump(project: Project): void {
    if (this.busy() || this.formOpen()) return;
    this.clearFeedback();
    this.deleteCandidate.set(null);
    this.versionBumpCandidate.set(project);
    this.versionBumpType.set('patch');
  }

  setVersionBumpType(event: Event): void {
    const target = event.target;
    if (!(target instanceof HTMLSelectElement)) return;
    if (target.value === 'patch' || target.value === 'minor' || target.value === 'major') {
      this.versionBumpType.set(target.value);
    }
  }

  confirmVersionBump(): void {
    const project = this.versionBumpCandidate();
    if (project === null || this.busy() || this.formOpen()) return;
    const type = this.versionBumpType();
    this.clearFeedback();
    this.bumpingVersion.set(true);
    this.api.bumpVersion(project.id, type)
      .pipe(finalize(() => this.bumpingVersion.set(false)))
      .subscribe({
        next: ({ data }) => {
          this.projects.update((projects) => projects.map((item) => item.id === data.project.id ? data.project : item));
          this.scanResult.update((result) => result === null ? null : {
            ...result,
            projects: result.projects.map((candidate) => candidate.local_path === data.project.local_path
              ? { ...candidate, current_version: data.project.current_version } : candidate),
          });
          this.versionBumpCandidate.set(null);
          this.notice.set(`Versión actualizada a ${data.project.current_version}. ${
            project.type === 'angular'
              ? `Archivos modificados: ${data.updated_files.join(', ') || 'npm no reportó cambios de archivos'}.`
              : 'Solo cambió el metadato de Deploy Tool; composer.json no se modificó.'
          }`);
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  cancelVersionBump(): void {
    if (!this.busy()) this.versionBumpCandidate.set(null);
  }

  private updateRegistration(path: string, id: number | null, angularProject: string | null): void {
    this.scanResult.update((result) => result === null ? null : {
      ...result,
      projects: result.projects.map((candidate) => candidate.local_path === path
        ? { ...candidate, registered_id: id, registered_angular_project: angularProject } : candidate),
    });
  }

  toggleHidden(): void {
    if (this.busy()) return;
    const rescan = this.scanResult() !== null;
    this.includeHidden.update((value) => !value);
    this.scanResult.set(null);
    this.load(rescan);
  }

  setVisibility(path: string, hidden: boolean): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.changingVisibility.set(true);
    this.api.visibility(path, hidden).pipe(finalize(() => this.changingVisibility.set(false))).subscribe({
      next: () => {
        this.projects.update((projects) => projects
          .map((project) => project.local_path === path ? { ...project, hidden_reason: hidden ? 'Ocultado manualmente' : null } : project)
          .filter((project) => this.includeHidden() || !project.hidden_reason));
        this.scanResult.update((result) => {
          if (result === null) return null;
          const delta = result.projects.filter((candidate) => candidate.local_path === path)
            .reduce((count, candidate) => count + Number(hidden) - Number(!!candidate.hidden_reason), 0);
          return {
            ...result, hidden_count: Math.max(0, (result.hidden_count ?? 0) + delta),
            projects: result.projects
              .map((candidate) => candidate.local_path === path ? { ...candidate, hidden_reason: hidden ? 'Ocultado manualmente' : null } : candidate)
              .filter((candidate) => this.includeHidden() || !candidate.hidden_reason),
          };
        });
        this.notice.set(hidden ? 'Proyecto ocultado. Activa Mostrar ocultos para recuperarlo.' : 'Proyecto visible nuevamente.');
        if (hidden && this.form.controls.local_path.value === path) this.formOpen.set(false);
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  private clearFeedback(): void {
    this.error.set('');
    this.notice.set('');
  }

  private matchesType(type: Project['type']): boolean {
    return this.typeFilter() === 'all' || this.typeFilter() === type;
  }

  private focusEditor(): void {
    afterNextRender(() => this.nameInput()?.nativeElement.focus(), { injector: this.injector });
  }
}
