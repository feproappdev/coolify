# Coolify Fork - Bug Fix Plan

## Repository
- **Fork**: https://github.com/feproappdev/coolify
- **Branch**: v4.x
- **Base Version**: 4.0.0-beta.459

## Issues to Fix

### Priority 1: Critical (Blocking Production)

#### 1. Deployment Reliability
- **Problem**: Deployments only work sometimes, unclear logs
- **Root Cause**: 
  - Swarm digest verification failures
  - Registry authentication issues across nodes
  - Race conditions in deployment job
- **Files to modify**:
  - `app/Jobs/ApplicationDeploymentJob.php`
  - `app/Actions/Application/StopApplication.php`
  - `bootstrap/helpers/docker.php`

#### 2. Swarm Mode Deployments
- **Problem**: Service deployments broken in full Swarm mode
- **Root Cause**: 
  - Coolify tries to verify image digest but can't reach private registry
  - Labels not properly applied for Swarm services
- **Files to modify**:
  - `app/Jobs/ApplicationDeploymentJob.php`
  - `bootstrap/helpers/applications.php`

#### 3. Persistent Storage UI ✅ FIXED
- **Problem**: Storage settings don't show after saving
- **Root Cause**: Livewire component not refreshing data properly after add/update
- **Solution Applied**:
  - Modified `app/Livewire/Project/Shared/Storages/All.php` - Changed listener to properly reload relationship data
  - Modified `app/Livewire/Project/Shared/Storages/Show.php` - Added hydrate() method to sync data on re-render
  - Modified `app/Livewire/Project/Service/Storage.php` - Added dispatch('refreshStorages') after data changes

### Priority 2: Important Features

#### 4. Force Redeploy Button ✅ FIXED
- **Problem**: Missing from UI for Swarm mode
- **Solution Applied**: Added dropdown with "Redeploy Stack" and "Force Rebuild" options
- **Files modified**:
  - `resources/views/livewire/project/application/heading.blade.php`

#### 5. Multi-Server Deployment
- **Problem**: Can't deploy same image to multiple servers
- **Root Cause**: Coolify architecture (1 app = 1 server)
- **Solution**: Add "Clone to Server" feature or service groups
- **Files to modify**:
  - New Livewire component needed
  - Database migration for service groups

#### 6. Container Metrics Dashboard
- **Problem**: No per-container metrics visible
- **Solution**: Integrate with Docker stats API or Prometheus
- **Files to modify**:
  - New Livewire component
  - New API endpoint

### Priority 3: UI/UX

#### 7. Dark/Light Mode Toggle ✅ FIXED
- **Problem**: Theme switching broken after update - data-theme attribute not updated on toggle
- **Root Cause**: queryTheme() function only toggled 'dark' class but not 'data-theme' attribute
- **Solution Applied**:
  - Modified `resources/views/livewire/settings-dropdown.blade.php` - Added data-theme attribute updates
  - Modified `resources/views/components/navbar.blade.php` - Added data-theme attribute updates

#### 8. Deployment Logs Clarity
- **Problem**: Logs not clear enough to debug issues
- **Solution**: Add structured logging with timestamps and stages
- **Files to modify**:
  - `app/Jobs/ApplicationDeploymentJob.php`
  - Log viewer component

---

## Implementation Order

1. **Fix Deployment Reliability** (most critical)
2. **Fix Swarm Mode** (needed for HA)
3. ~~**Add Force Redeploy Button**~~ ✅ DONE
4. ~~**Fix Persistent Storage UI**~~ ✅ DONE
5. ~~**Fix Dark/Light Mode**~~ ✅ DONE
6. **Add Container Metrics** (nice to have)
7. **Multi-Server Deployment** (complex, later)

---

## Build & Deploy Custom Image

```bash
cd /root/coolify-fork
./build-custom-image.sh
```

## Testing Checklist

- [ ] Deploy Docker Image app
- [ ] Deploy Dockerfile app
- [ ] Deploy to Swarm cluster
- [ ] Add persistent storage
- [ ] Force redeploy
- [ ] Check metrics
- [x] Toggle dark/light mode
