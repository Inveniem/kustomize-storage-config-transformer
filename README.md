# Kustomize Storage Config Transformer
As a DevOps professional who is hosting the same containerized software for
multiple tenants on the same Kubernetes infrastructure and who needs to keep the
data for each client in a separate volume, the Kustomize Storage Config
Transformer is a KRM function that can take in a list of storage volume names
and transform them into appropriate PV, PVC, volume mounts, and container mounts
in deployment manifests.

For example, given a base deployment manifest like this:
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
  name: some-other-app
spec:
  replicas: 1
  selector:
    matchLabels:
      app: some-other-app
  template:
    metadata:
      labels:
        app: some-other-app
    spec:
      containers:
        - name: some-other-app
          image: "inveniem/some-other-app:latest"
          ports:
            - containerPort: 5000
```

A Kustomization config like this:
```yaml
# overlays/live/kustomization.yaml
apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

resources:
  - ../../base

transformers:
  - configure-storage.yaml
```

And a KSCT plugin config like this:
```yaml
# overlays/live/configure-storage.yaml
apiVersion: storage-config-transformer.kubernetes.inveniem.com/v1
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
          shareName: "<<DYNAMIC>>"
      name:
        prefix: "pv-myapp-live-"
        suffix: ~
      injectedValues:
        - field: "spec.azureFile.shareName"
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
        volumeName: "<<DYNAMIC>>"
      name:
        prefix: pvc-
        suffix: ~
      injectedValues:
        - field: "spec.volumeName"
          prefix: "pv-myapp-live-"
          suffix: ~

    containerVolumeTemplates:
      - containers:
          - name: frontend-myapp
          - name: backend-myapp-api

        volumeTemplates:
          - mergeSpec:
              persistentVolumeClaim:
                claimName: "<<DYNAMIC>>"
            name:
              prefix: "vol-"
              suffix: ~
            injectedValues:
              - field: "persistentVolumeClaim.claimName"
                prefix: "pvc-"
                suffix: ~

        volumeMountTemplates:
          - mergeSpec:
              mountPath: "<<DYNAMIC>>"
            name:
              prefix: "vol-mnt-"
              suffix: ~
            injectedValues:
              - field: "mountPath"
                prefix: "/mnt/share/"
                suffix: ~
```

This should produce the following deployment manifest:
```yaml
apiVersion: v1
kind: PersistentVolume
metadata:
  name: "pv-myapp-live-sample-project1"
spec:
  capacity:
    storage: 1Ti
  accessModes:
    - ReadWriteMany
  azureFile:
    secretName: "myapp-azure-files-creds"
    shareName: "sample-project1"
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: "pvc-sample-project1"
spec:
  storageClassName: ""
  accessModes:
    - ReadWriteMany
  resources:
    requests:
      storage: 1Ti
  volumeName: "pv-myapp-live-sample-project1"
---
apiVersion: v1
kind: PersistentVolume
metadata:
  name: "pv-myapp-live-sample-project2"
spec:
  capacity:
    storage: 1Ti
  accessModes:
    - ReadWriteMany
  azureFile:
    secretName: "myapp-azure-files-creds"
    shareName: "sample-project2"
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: "pvc-sample-project2"
spec:
  storageClassName: ""
  accessModes:
    - ReadWriteMany
  resources:
    requests:
      storage: 1Ti
  volumeName: "pv-myapp-live-sample-project2"
---
apiVersion: v1
kind: PersistentVolume
metadata:
  name: "pv-myapp-live-sample-project3"
spec:
  capacity:
    storage: 1Ti
  accessModes:
    - ReadWriteMany
  azureFile:
    secretName: "myapp-azure-files-creds"
    shareName: "sample-project3"
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: "pvc-sample-project3"
spec:
  storageClassName: ""
  accessModes:
    - ReadWriteMany
  resources:
    requests:
      storage: 1Ti
  volumeName: "pv-myapp-live-sample-project3"
---
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
      volumes:
        - name: "vol-sample-project1"
          persistentVolumeClaim:
            claimName: "pvc-sample-project1"
        - name: "vol-sample-project2"
          persistentVolumeClaim:
            claimName: "pvc-sample-project2"
        - name: "vol-sample-project3"
          persistentVolumeClaim:
            claimName: "pvc-sample-project3"
      containers:
        - name: backend-myapp-api
          image: "inveniem/myapp-api:latest"
          ports:
            - containerPort: 5000
          volumeMounts:
            - mountPath: "/mnt/share/sample-project1"
              name: "vol-mnt-sample-project1"
            - mountPath: "/mnt/share/sample-project2"
              name: "vol-mnt-sample-project2"
            - mountPath: "/mnt/share/sample-project3"
              name: "vol-mnt-sample-project3"

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
      volumes:
        - name: "vol-sample-project1"
          persistentVolumeClaim:
            claimName: "pvc-sample-project1"
        - name: "vol-sample-project2"
          persistentVolumeClaim:
            claimName: "pvc-sample-project2"
        - name: "vol-sample-project3"
          persistentVolumeClaim:
            claimName: "pvc-sample-project3"
      containers:
        - name: frontend-myapp
          image: "inveniem/frontend-myapp:latest"
          ports:
            - containerPort: 5000
          volumeMounts:
            - mountPath: "/mnt/share/sample-project1"
              name: "vol-mnt-sample-project1"
            - mountPath: "/mnt/share/sample-project2"
              name: "vol-mnt-sample-project2"
            - mountPath: "/mnt/share/sample-project3"
              name: "vol-mnt-sample-project3"
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: some-other-app
spec:
  replicas: 1
  selector:
    matchLabels:
      app: some-other-app
  template:
    metadata:
      labels:
        app: some-other-app
    spec:
      containers:
        - name: some-other-app
          image: "inveniem/some-other-app:latest"
          ports:
            - containerPort: 5000
```
