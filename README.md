# Kustomize Storage Config Transformer
As a DevOps professional who is hosting the same containerized software for
multiple tenants on the same Kubernetes infrastructure and who needs to keep the
data for each client in a separate volume (potentially even in different storage
accounts like Azure Files, Azure Blob, S3, Qumulo, etc.), the Kustomize Storage
Config Transformer is a KRM function that can take in a list of tenant names and
transform them into deployment manifests that contain appropriate declarations
of Persistent Volumes (PVs), Persistent Volume Claims (PVCs), volume mounts, and
container mounts.

For example, consider the following base deployment manifest:
```yaml
# base/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: myapp-api
spec:
  replicas: 2
  selector:
    matchLabels:
      app: backend-myapp-api
  template:
    metadata:
      labels:
        app: backend-myapp-api
        role: backend
    spec:
      containers:
        - name: backend-myapp-api
          image: "inveniem/myapp-api:latest"
          ports:
            - containerPort: 5000
        - name: some-other-app1
          image: "inveniem/some-other-app1:latest"
          ports:
            - containerPort: 5000

---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: frontend-myapp
spec:
  replicas: 1
  selector:
    matchLabels:
      app: frontend-myapp
  template:
    metadata:
      labels:
        app: frontend-myapp
        role: frontend
    spec:
      containers:
        - name: frontend-myapp
          image: "inveniem/frontend-myapp:latest"
          ports:
            - containerPort: 5000
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: some-other-app2
spec:
  replicas: 1
  selector:
    matchLabels:
      app: some-other-app2
  template:
    metadata:
      labels:
        app: some-other-app2
    spec:
      containers:
        - name: some-other-app2
          image: "inveniem/some-other-app2:latest"
          ports:
            - containerPort: 5000
```

Now, consider a "live" overlay that has a Kustomization config like this:
```yaml
# overlays/live/kustomization.yaml
apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

resources:
  - ../../base

namespace: sample

transformers:
  - configure-storage.yaml
```

And a KSCT plugin config like this:
```yaml
# overlays/live/configure-storage.yaml
apiVersion: kubernetes.inveniem.com/storage-config-transformer/v1alpha
kind: StorageConfigTransformer
metadata:
  name: storage-config-transformer
  annotations:
    config.kubernetes.io/function: |
      container:
        image: inveniem/kustomize-storage-transformer:latest
spec:
  - permutations:
      values:
        - sample-project1
        - sample-project2
        - sample-project3
            
    persistentVolumeTemplate:
      spec:
        capacity:
          storage: 1Ti
        accessModes:
          - ReadWriteMany
        azureFile:
          secretName: "myapp-azure-files-creds"
          shareName: "<<INJECTED>>"
      name:
        prefix: "pv-myapp-live-"
        suffix: ~
      injectedValues:
        # "targetField" is the new name for "field" from v1.0.0. Both "field"
        # and "targetField" are allowed are allowed in v1.1.0+, but 
        # "targetField" will be supported as the clearer option going forward.
        - targetField: "spec.azureFile.shareName"
          prefix: ~
          suffix: ~

    persistentVolumeClaimTemplate:
      spec:
        storageClassName: ""
        accessModes:
          - ReadWriteMany
        resources:
          requests:
            storage: 1Ti
        volumeName: "<<INJECTED>>"
      name:
        prefix: pvc-
        suffix: ~
      namespace: sample
      injectedValues:
        # "targetField" is the new name for "field" from v1.0.0. Both "field"
        # and "targetField" are allowed are allowed in v1.1.0+, but 
        # "targetField" will be supported as the clearer option going forward.
        - targetField: "spec.volumeName"
          prefix: "pv-myapp-live-"
          suffix: ~

    containerVolumeTemplates:
      - containers:
          - name: frontend-myapp
          - name: backend-myapp-api

        volumeTemplates:
          - mergeSpec:
              persistentVolumeClaim:
                claimName: "<<INJECTED>>"
            name:
              prefix: "vol-"
              suffix: ~
            injectedValues:
              # "targetField" is the new name for "field" from v1.0.0. Both 
              # "field" and "targetField" are allowed in v1.1.0+, but 
              # "targetField" will be supported as the clearer option going 
              # forward.
              - targetField: "persistentVolumeClaim.claimName"
                prefix: "pvc-"
                suffix: ~

        volumeMountTemplates:
          - mergeSpec:
              mountPath: "<<INJECTED>>"
            name:
              prefix: "vol-"
              suffix: ~
            injectedValues:
              # "targetField" is the new name for "field" from v1.0.0. Both 
              # "field" and "targetField" are allowed in v1.1.0+, but 
              # "targetField" will be supported as the clearer option going 
              # forward.
              - targetField: "mountPath"
                prefix: "/mnt/share/"
                suffix: ~
```
_(Above, there is nothing special about `<<INJECTED>>` as a value; it's just
being used to make it easier to visualize where the injected values will go in
the output template. Keys that have that value in this example could actually
have been omitted entirely, and the corresponding value would still be created
and populated by the `injectedValues`.)_

This would produce the following deployment manifest:
```yaml
apiVersion: v1
kind: PersistentVolume
metadata:
  name: pv-myapp-live-sample-project1
spec:
  accessModes:
  - ReadWriteMany
  azureFile:
    secretName: myapp-azure-files-creds
    shareName: sample-project1
  capacity:
    storage: 1Ti
---
apiVersion: v1
kind: PersistentVolume
metadata:
  name: pv-myapp-live-sample-project2
spec:
  accessModes:
  - ReadWriteMany
  azureFile:
    secretName: myapp-azure-files-creds
    shareName: sample-project2
  capacity:
    storage: 1Ti
---
apiVersion: v1
kind: PersistentVolume
metadata:
  name: pv-myapp-live-sample-project3
spec:
  accessModes:
  - ReadWriteMany
  azureFile:
    secretName: myapp-azure-files-creds
    shareName: sample-project3
  capacity:
    storage: 1Ti
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: pvc-sample-project1
  namespace: sample
spec:
  accessModes:
  - ReadWriteMany
  resources:
    requests:
      storage: 1Ti
  storageClassName: ""
  volumeName: pv-myapp-live-sample-project1
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: pvc-sample-project2
  namespace: sample
spec:
  accessModes:
  - ReadWriteMany
  resources:
    requests:
      storage: 1Ti
  storageClassName: ""
  volumeName: pv-myapp-live-sample-project2
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: pvc-sample-project3
  namespace: sample
spec:
  accessModes:
  - ReadWriteMany
  resources:
    requests:
      storage: 1Ti
  storageClassName: ""
  volumeName: pv-myapp-live-sample-project3
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: frontend-myapp
  namespace: sample
spec:
  replicas: 1
  selector:
    matchLabels:
      app: frontend-myapp
  template:
    metadata:
      labels:
        app: frontend-myapp
        role: frontend
    spec:
      containers:
      - image: inveniem/frontend-myapp:latest
        name: frontend-myapp
        ports:
        - containerPort: 5000
        volumeMounts:
        - mountPath: /mnt/share/sample-project1
          name: vol-sample-project1
        - mountPath: /mnt/share/sample-project2
          name: vol-sample-project2
        - mountPath: /mnt/share/sample-project3
          name: vol-sample-project3
      volumes:
      - name: vol-sample-project1
        persistentVolumeClaim:
          claimName: pvc-sample-project1
      - name: vol-sample-project2
        persistentVolumeClaim:
          claimName: pvc-sample-project2
      - name: vol-sample-project3
        persistentVolumeClaim:
          claimName: pvc-sample-project3
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: myapp-api
  namespace: sample
spec:
  replicas: 2
  selector:
    matchLabels:
      app: backend-myapp-api
  template:
    metadata:
      labels:
        app: backend-myapp-api
        role: backend
    spec:
      containers:
      - image: inveniem/myapp-api:latest
        name: backend-myapp-api
        ports:
        - containerPort: 5000
        volumeMounts:
        - mountPath: /mnt/share/sample-project1
          name: vol-sample-project1
        - mountPath: /mnt/share/sample-project2
          name: vol-sample-project2
        - mountPath: /mnt/share/sample-project3
          name: vol-sample-project3
      - image: inveniem/some-other-app1:latest
        name: some-other-app1
        ports:
        - containerPort: 5000
      volumes:
      - name: vol-sample-project1
        persistentVolumeClaim:
          claimName: pvc-sample-project1
      - name: vol-sample-project2
        persistentVolumeClaim:
          claimName: pvc-sample-project2
      - name: vol-sample-project3
        persistentVolumeClaim:
          claimName: pvc-sample-project3
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: some-other-app2
  namespace: sample
spec:
  replicas: 1
  selector:
    matchLabels:
      app: some-other-app2
  template:
    metadata:
      labels:
        app: some-other-app2
    spec:
      containers:
      - image: inveniem/some-other-app2:latest
        name: some-other-app2
        ports:
        - containerPort: 5000
```

Note that the output manifests include the following:
1. A PV (available cluster-wide) is declared for each permutation value (e.g.,
   `sample-project1`, `sample-project2`, etc.), with:
   1. Each name prefixed according to the name prefix template.
   2. The specification (`spec`) for each PV is copied from the 
      `persistentVolumeTemplate` from the KSCT config.
   3. The share name is dynamically-injected based on the `injectedValues`
      settings in the KSCT config.

2. A PVC (declared in the namespace in which the application is being
   deployed) is declared for each permutation value, with:
   1. The name of each PVC prefixed according to the name prefix template.
   2. Each PVC bound to its corresponding PV via a dynamically-injected 
      `volumeName` attribute value.

3. Volumes, declared in each deployment that contains a container that is
   referenced by the `containerVolumeTemplates.containers` key in the
   KSCT config, to reference the PVs; with the settings for each one merged-in
   from the `mergeSpec` key of the volume template of the KSCT config.

4. Volume mounts, declared for each container that matches a name in the 
   `containerVolumeTemplates.containers` key of the KSCT config, with its
   settings merged-in from the `mergeSpec` key of the volume mount template of
   the KSCT config.
